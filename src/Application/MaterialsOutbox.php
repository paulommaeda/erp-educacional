<?php
declare(strict_types=1);
namespace EducacionalERP\Application;

use EducacionalERP\Domain\{RuleViolation,Store};
use EducacionalERP\Infrastructure\Materials\{Client,Settings};

final class MaterialsOutbox
{
    private const TABLE='materiais_envios';

    public function __construct(private Store $db){}

    /** Save the task in the same transaction as the enrollment. */
    public function enqueue(int $enrollment,int $company,int $period):void
    {
        $settings=Coligadas::within($company,static fn()=>Settings::read());
        if(!Settings::automatic($settings)||(int)$settings['codperiodo']!==$period)return;
        $this->db->insert(self::TABLE,[
            'codcoligada'=>$company,'idmatricula'=>$enrollment,
            'destino_hash'=>hash('sha256',$settings['url']),'tentar_apos'=>gmdate('Y-m-d H:i:s')
        ]);
    }

    public function status():array
    {
        $table=$this->db->table(self::TABLE);
        $enrollments=$this->db->table('matriculas');
        $students=$this->db->table('alunos');
        $company=Coligadas::current();
        $counts=$this->db->rows("SELECT situacao,COUNT(*) AS n FROM $table WHERE codcoligada=%d GROUP BY situacao",[$company]);
        $latest=$this->db->rows("SELECT j.idmatricula,a.ra,j.situacao,j.tentativas,j.erro,j.tentar_apos,j.confirmado_em
            FROM $table j JOIN $enrollments m ON m.idmatricula=j.idmatricula
            JOIN $students a ON a.idaluno=m.idaluno WHERE j.codcoligada=%d ORDER BY j.idenvio DESC LIMIT 15",[$company]);
        return ['totais'=>array_column($counts,'n','situacao'),'recentes'=>$latest];
    }

    public function process():void
    {
        $table=$this->db->table(self::TABLE);
        $companies=$this->db->table('coligadas');
        $now=gmdate('Y-m-d H:i:s');
        $active=[];
        foreach($this->db->rows("SELECT DISTINCT j.codcoligada FROM $table j
            JOIN $companies c ON c.codcoligada=j.codcoligada
            WHERE j.situacao='pendente' AND j.tentar_apos<=%s AND c.ativo=1",[$now]) as $row){
            $company=(int)$row['codcoligada'];
            if(Coligadas::within($company,static fn()=>Settings::automatic(Settings::read())))$active[]=$company;
        }
        if(!$active)return;
        $marks=implode(',',array_fill(0,count($active),'%d'));
        $jobs=$this->db->rows("SELECT idenvio,codcoligada FROM $table
            WHERE situacao='pendente' AND tentar_apos<=%s AND codcoligada IN ($marks)
            ORDER BY tentar_apos,idenvio LIMIT 40",[$now,...$active]);
        $deadline=microtime(true)+15;
        foreach($jobs as $job){
            if(microtime(true)>=$deadline)break;
            Coligadas::within((int)$job['codcoligada'],fn()=>$this->deliver((int)$job['idenvio'],$now));
        }
    }

    private function deliver(int $id,string $now):void
    {
        $lock=Settings::lock($this->db);
        if((int)($this->db->row('SELECT GET_LOCK(%s,0) AS acquired',[$lock])['acquired']??0)!==1)return;
        try{
            wp_cache_delete(Settings::option(),'options');
            $settings=Settings::read();
            if(!Settings::automatic($settings))return;
            $jobs=$this->db->table(self::TABLE);
            $enrollments=$this->db->table('matriculas');
            $row=$this->db->row("SELECT j.*,m.idaluno,m.codperiodo,m.excluido_em,m.ativo_unico FROM $jobs j
                JOIN $enrollments m ON m.idmatricula=j.idmatricula AND m.codcoligada=j.codcoligada
                WHERE j.idenvio=%d AND j.codcoligada=%d",[$id,Coligadas::current()]);
            if(!$row||$row['situacao']!=='pendente'||$row['tentar_apos']>$now)return;
            if((int)$row['codperiodo']!==(int)$settings['codperiodo']||!hash_equals($row['destino_hash'],hash('sha256',$settings['url']))){
                $this->db->update(self::TABLE,$id,['situacao'=>'cancelado','erro'=>'O período ou o destino do K8 mudou.']);
                return;
            }
            if(!empty($row['excluido_em'])||(int)$row['ativo_unico']!==1){
                $this->db->update(self::TABLE,$id,['situacao'=>'cancelado','erro'=>'Matrícula sem turma ativa para envio.']);
                return;
            }
            $tries=(int)$row['tentativas']+1;
            try{
                $student=$this->db->atomic(fn()=>(new MaterialsSync($this->db))->forEnrollment((int)$row['idaluno'],Coligadas::current(),(int)$settings['codperiodo']));
                if(!$student){
                    $this->db->update(self::TABLE,$id,['situacao'=>'cancelado','erro'=>'Aluno ou matrícula indisponível para envio.']);
                    return;
                }
                if(strlen(wp_json_encode($student))+80>MaterialsSync::BATCH_BYTES)throw new RuleViolation('O cadastro excede o tamanho permitido para o K8.');
                $result=Client::send($settings,[$student],false,10);
            }catch(RuleViolation $error){
                $code=$error->getCode();
                $retry=$tries<4&&($code===429||($code>=500&&$code<=599));
                $this->db->update(self::TABLE,$id,['situacao'=>$retry?'pendente':'falha','tentativas'=>$tries,
                    'erro'=>$error->getMessage(),'tentar_apos'=>gmdate('Y-m-d H:i:s',time()+300*2**($tries-1))]);
                return;
            }
            $this->db->update(self::TABLE,$id,['situacao'=>'confirmado','tentativas'=>$tries,
                'erro'=>$result['aviso_base'],'confirmado_em'=>gmdate('Y-m-d H:i:s')]);
        }finally{$this->db->row('SELECT RELEASE_LOCK(%s) AS released',[$lock]);}
    }
}
