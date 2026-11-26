<?php
declare(strict_types=1);
namespace EducacionalERP\Application;

use EducacionalERP\Domain\{RuleViolation,Store};
use EducacionalERP\Infrastructure\WordPress\{MenuPolicy,ScholarshipRequestMail};
use EducacionalERP\Presentation\Portal\ScholarshipRequestPage;

final class ScholarshipApprovalLinks
{
    public const MAIL_TYPE='aprovacao_link';
    public const UNAVAILABLE='Este link de aprovação é inválido, expirou ou o requerimento já foi decidido.';
    private const TABLE='requerimento_bolsa_emails';

    public function __construct(private Store $db) {}

    public static function eligible(int $id): ?\WP_User
    {
        $user=$id?get_user_by('id',$id):false;
        return $user&&is_email($user->user_email)&&MenuPolicy::canDecideScholarshipRequests($user)?$user:null;
    }

    public static function recipients(): array
    {
        $roles=MenuPolicy::scholarshipDecisionRoles();
        if(!$roles)return [];
        $result=[];
        foreach(get_users(['role__in'=>$roles,'orderby'=>'display_name']) as $user){
            if(is_email($user->user_email)&&MenuPolicy::canDecideScholarshipRequests($user)){
                $result[]=['id'=>$user->ID,'nome'=>$user->display_name,'email'=>$user->user_email];
            }
        }
        return $result;
    }

    public function queue(int $request,int $recipient): void
    {
        $user=self::eligible($recipient);
        if(!$user)return;
        (new ScholarshipRequestMail($this->db))->queue($request,self::MAIL_TYPE,[strtolower($user->user_email)],[
            'aprovador_id'=>$user->ID,'link_referencia'=>bin2hex(random_bytes(16)),
            'link_expira_em'=>gmdate('Y-m-d H:i:s',time()+2*DAY_IN_SECONDS),
        ]);
    }

    public static function url(array $link): ?string
    {
        if(!self::recipient($link))return null;
        return ScholarshipRequestPage::approvalUrl((int)$link['codcoligada']).'#'.$link['idemail'].'.'.self::signature($link);
    }

    public function verify(mixed $token): array
    {
        if(!is_string($token)||!preg_match('/^([1-9][0-9]{0,17})\.([a-f0-9]{64})$/D',$token,$parts)){
            throw new RuleViolation(self::UNAVAILABLE);
        }
        $table=$this->db->table(self::TABLE);
        $link=$this->db->row("SELECT * FROM $table WHERE codcoligada=%d AND idemail=%d AND tipo=%s",
            [Coligadas::current(),(int)$parts[1],self::MAIL_TYPE]);
        if(!$link||!hash_equals(self::signature($link),$parts[2])||!self::recipient($link)){
            throw new RuleViolation(self::UNAVAILABLE);
        }
        return $link;
    }

    private static function recipient(array $link): ?\WP_User
    {
        if($link['status']==='cancelado'||!$link['link_referencia']||$link['link_expira_em']<=gmdate('Y-m-d H:i:s'))return null;
        $id=(int)$link['aprovador_id'];
        if($id!==(int)ScholarshipRequests::configuration()['aprovador_id'])return null;
        $user=self::eligible($id);
        return $user&&strtolower($user->user_email)===$link['destinatario']?$user:null;
    }

    private static function signature(array $link): string
    {
        $claims=implode('|',[$link['idemail'],$link['codcoligada'],$link['idpedido'],$link['aprovador_id'],
            $link['destinatario'],$link['link_referencia'],$link['link_expira_em']]);
        return hash_hmac('sha256','ederp:scholarship-approval:v1|'.$claims,wp_salt('auth'));
    }
}
