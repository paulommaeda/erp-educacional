<?php
declare(strict_types=1);
namespace EducacionalERP\Application;

use EducacionalERP\Domain\{CadastroText,Input,RuleViolation,ScholarshipRequestForm,Store};
use EducacionalERP\Infrastructure\WordPress\{Access,MenuPolicy,ScholarshipRequestMail};
use EducacionalERP\Presentation\Portal\ScholarshipRequestPage;

final class ScholarshipRequests
{
    public function __construct(private Store $db) {}

    public static function configuration(): array
    {
        $settings=get_option(Coligadas::option('ederp_scholarship_requests'),[]);
        return (is_array($settings)?$settings:[])+['ativo'=>false,'emails'=>[]];
    }

    public static function settings(): array
    {
        Access::requireAdmin();
        return self::configuration()+['url'=>ScholarshipRequestPage::url(Coligadas::current())];
    }

    public static function saveSettings(array $data): array
    {
        Access::requireAdmin();
        if(!is_bool($data['ativo']??null)||!is_string($data['emails']??null)||strlen($data['emails'])>6000)throw new RuleViolation('Confira as configurações do requerimento.');
        $emails=[];
        foreach(preg_split('/[\r\n,;]+/',$data['emails']) as $email){
            $email=strtolower(trim($email));
            if($email==='')continue;
            if(strlen($email)>191||!is_email($email))throw new RuleViolation('Confira os e-mails da administração.');
            $emails[$email]=$email;
        }
        if(count($emails)>20)throw new RuleViolation('Informe até 20 destinatários.');
        if($data['ativo']&&!$emails)throw new RuleViolation('Informe ao menos um e-mail para receber os novos requerimentos.');
        update_option(Coligadas::option('ederp_scholarship_requests'),['ativo'=>$data['ativo'],'emails'=>array_values($emails)],false);
        return self::settings();
    }

    public static function reviewAccess(): void
    {
        if(!MenuPolicy::can('requerimentos_bolsa'))throw new RuleViolation('Sem acesso aos requerimentos de bolsa.');
    }

    public function submit(array $input,string $key): array
    {
        $settings=self::configuration();
        if(!$settings['ativo'])throw new RuleViolation('O recebimento de requerimentos de bolsa está fechado.');
        $data=ScholarshipRequestForm::validate($input);
        $result=$this->db->atomic(fn()=>(new Operations($this->db))->run($key,'enviar_requerimento_bolsa',$data,function()use($data,$settings){
            $protocol='BOL-'.strtoupper(bin2hex(random_bytes(10)));
            $id=$this->db->insert('requerimentos_bolsa',['idaluno'=>$this->matchStudent($data),'protocolo'=>$protocol,
                'nome_aluno'=>$data['aluno'],'dados_json'=>wp_json_encode($data)]);
            $mail=new ScholarshipRequestMail($this->db);
            $mail->queue($id,'recebido',[$data['email']]);
            $mail->queue($id,'administracao',$settings['emails']);
            return ['protocolo'=>$protocol,'idpedido'=>$id];
        }));
        (new ScholarshipRequestMail($this->db))->dispatch((int)$result['idpedido']);
        return ['protocolo'=>$result['protocolo']];
    }

    public function listing(mixed $status,mixed $page): array
    {
        self::reviewAccess();
        if(!in_array($status,['todos','pendente','aprovado','rejeitado'],true))throw new RuleViolation('Situação inválida.');
        $page=min(100000,Input::id($page));
        $table=$this->db->table('requerimentos_bolsa');
        $args=[Coligadas::current()];
        $where=' WHERE codcoligada=%d';
        if($status!=='todos'){$where.=' AND situacao=%s';$args[]=$status;}
        $items=$this->db->rows("SELECT idpedido,protocolo,situacao,nome_aluno,idaluno,percentual_aprovado,criado_em FROM $table"
            .$where.' ORDER BY idpedido DESC LIMIT 21 OFFSET %d',[...$args,($page-1)*20]);
        return ['items'=>array_slice($items,0,20),'mais'=>count($items)>20];
    }

    public function detail(int $id): array
    {
        self::reviewAccess();
        $row=$this->request($id);
        $row['dados']=json_decode($row['dados_json'],true,32,JSON_THROW_ON_ERROR);
        unset($row['dados_json']);
        $row['aluno']=$row['idaluno']?$this->student((int)$row['idaluno']):null;
        $row['notificacoes']=(new ScholarshipRequestMail($this->db))->status($id);
        return $row;
    }

    public function candidates(int $id,mixed $query): array
    {
        self::reviewAccess();
        $this->request($id);
        if(!is_string($query)||strlen(trim($query))<2)return [];
        $term='%'.addcslashes(Input::text($query,191),'_%\\').'%';
        return $this->students(' AND (p.nome LIKE %s OR a.ra LIKE %s) ORDER BY p.nome,a.ra LIMIT 30',[$term,$term]);
    }

    public function link(int $id,array $input,string $key): array
    {
        self::reviewAccess();
        $student=Input::id($input['idaluno']??null);
        return $this->db->atomic(fn()=>(new Operations($this->db))->run($key,'vincular_requerimento_bolsa',['id'=>$id]+$input,function()use($id,$student,$input,$key){
            $request=$this->pending($id,$input);
            $row=$this->student($student);
            if(!$row||!(int)$row['ativo'])throw new RuleViolation('Selecione um aluno ativo desta coligada.');
            $this->db->update('requerimentos_bolsa',$id,['idaluno'=>$student]);
            $this->db->audit('requerimentos_bolsa',$id,'vincular',['idaluno'=>$request['idaluno']],['idaluno'=>$student],$key);
            return ['vinculado'=>true];
        }));
    }

    public function decide(int $id,array $input,string $key): array
    {
        self::reviewAccess();
        $status=$input['situacao']??null;
        if(!in_array($status,['aprovado','rejeitado'],true))throw new RuleViolation('Escolha aprovar ou rejeitar o requerimento.');
        $percent=$status==='aprovado'?ScholarshipRequestForm::percentage($input['percentual']??null):null;
        $note=$input['observacao']??'';
        $note=$note===''?null:Input::text($note,2000);
        $result=ContractTextLock::run($this->db,fn()=>$this->db->atomic(fn()=>(new Operations($this->db))->run($key,'decidir_requerimento_bolsa',
            ['id'=>$id]+$input,function()use($id,$input,$status,$percent,$note,$key){
                $request=$this->pending($id,$input);
                $grant=null;
                if($status==='aprovado'){
                    $student=(int)$request['idaluno'];
                    if(!$student)throw new RuleViolation('Vincule o aluno antes de aprovar.');
                    $target=$this->db->get('alunos',$student,true);
                    if(!(int)$target['ativo']||!(int)$this->db->get('pessoas',(int)$target['codpessoa'])['ativo'])throw new RuleViolation('Vincule um aluno ativo antes de aprovar.');
                    $grant=(new StudentScholarships($this->db))->saveInside($student,null,['titulo'=>'Bolsa aprovada · '.$request['protocolo'],
                        'descricao'=>'Benefício concedido pelo requerimento '.$request['protocolo'].'.','percentual'=>$percent,'ativo'=>1],$key);
                }
                $changes=['situacao'=>$status,'percentual_aprovado'=>$percent,'idbolsa_aluno'=>$grant['idbolsa_aluno']??null,
                    'observacao'=>$note,'decidido_em'=>gmdate('Y-m-d H:i:s'),'decidido_por'=>get_current_user_id()];
                $this->db->update('requerimentos_bolsa',$id,$changes);
                $this->db->audit('requerimentos_bolsa',$id,$status,['situacao'=>'pendente','idaluno'=>$request['idaluno']],$changes,$key);
                $data=json_decode($request['dados_json'],true,32,JSON_THROW_ON_ERROR);
                (new ScholarshipRequestMail($this->db))->queue($id,'decisao',[$data['email']]);
                return ['situacao'=>$status,'bolsa'=>$grant];
            })));
        (new ScholarshipRequestMail($this->db))->dispatch($id);
        return $result;
    }

    private function request(int $id,bool $lock=false): array
    {
        $table=$this->db->table('requerimentos_bolsa');
        $row=$this->db->row("SELECT * FROM $table WHERE codcoligada=%d AND idpedido=%d".($lock?' FOR UPDATE':''),[Coligadas::current(),$id]);
        if(!$row)throw new RuleViolation('Requerimento indisponível nesta coligada.');
        return $row;
    }

    private function pending(int $id,array $input): array
    {
        $row=$this->request($id,true);
        if($row['situacao']!=='pendente'||(int)$row['versao']!==Input::id($input['versao']??null)){
            throw new RuleViolation('O requerimento foi alterado ou já recebeu uma decisão. Atualize a análise.');
        }
        return $row;
    }

    private function student(int $id): ?array
    {
        return $this->students(' AND a.idaluno=%d',[$id],false)[0]??null;
    }

    private function matchStudent(array $data): ?int
    {
        $fold=static fn(string $name)=>CadastroText::upper(remove_accents(trim(preg_replace('/\s+/u',' ',$name))));
        $name=$fold($data['aluno']);
        $matches=array_values(array_filter($this->students(' AND p.data_nascimento=%s',[$data['nascimento']]),static fn($row)=>$fold($row['nome'])===$name));
        return count($matches)===1?(int)$matches[0]['idaluno']:null;
    }

    private function students(string $extra,array $args,bool $active=true): array
    {
        $students=$this->db->table('alunos');$people=$this->db->table('pessoas');
        return $this->db->rows("SELECT a.idaluno,a.ra,a.ativo,p.nome,p.data_nascimento FROM $students a JOIN $people p ON p.codpessoa=a.codpessoa"
            .' WHERE a.codcoligada=%d'.($active?' AND a.ativo=1 AND p.ativo=1':'').$extra,[Coligadas::current(),...$args]);
    }
}
