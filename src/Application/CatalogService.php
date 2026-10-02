<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Domain\Store;
use EducacionalERP\Domain\{Input,RuleViolation,Relationships};
use EducacionalERP\Infrastructure\WordPress\{Accounts,Access};
final class CatalogService
{
    public const TABLES=['pessoas','alunos','periodos_letivos','cursos','turnos','turmas','planos_pagamento'];
    private Accounts $accounts;
    public function __construct(private Store $db,private Operations $ops,?Accounts $accounts=null) { $this->accounts=$accounts??new Accounts($db); }
    public function create(string $table,array $d,string $key): array
    {
        $d=\EducacionalERP\Domain\CadastroText::normalize($d);
        if (!in_array($table,self::TABLES,true)) { throw new RuleViolation('Cadastro não permitido.'); }
        return $this->db->atomic(fn()=>$this->ops->run($key,'criar_'.$table,$d,function()use($table,$d,$key){
            return $this->createInside($table,$d,$key);
        }));
    }
    /** Caller owns the transaction; shared by person+student creation. */
    public function createInside(string $table,array $d,string $key): array
    {
        $d=\EducacionalERP\Domain\CadastroText::normalize($d);
        if (!in_array($table,self::TABLES,true)) { throw new RuleViolation('Cadastro não permitido.'); }
            if($table==='planos_pagamento')Access::requireAdmin();
            switch ($table) {
                case 'pessoas':
                    $data=$this->personData($d);
                    break;
                case 'alunos': $data=['codpessoa'=>Input::id($d['codpessoa']??null),'ra'=>Input::text($d['ra']??null,40),'tipo_aluno'=>\EducacionalERP\Domain\PersonFields::studentType($d['tipo_aluno']??'Regular')]; break;
                case 'periodos_letivos':
                    $data=['codigo'=>Input::text($d['codigo']??null,30),'descricao'=>Input::text($d['descricao']??null),'data_inicio'=>Input::date($d['data_inicio']??null),'data_fim'=>Input::date($d['data_fim']??null),'status'=>'aberto'];
                    if ($data['data_fim']<$data['data_inicio']) { throw new RuleViolation('Período com datas invertidas.'); } $data['codperiodo_proximo']=$this->nextPeriod($d,$data['data_inicio']); break;
                case 'planos_pagamento': $this->db->get('periodos_letivos',Input::id($d['codperiodo']??null));$data=['codperiodo'=>Input::id($d['codperiodo']),'codigo'=>Input::text($d['codigo']??null,30),'nome'=>Input::text($d['nome']??null,100),'valor_anuidade'=>\EducacionalERP\Domain\Money::format(\EducacionalERP\Domain\Money::positive($d['valor_anuidade']??null))];break;
                case 'cursos': case 'turnos': $data=['codigo'=>Input::text($d['codigo']??null,30),'nome'=>Input::text($d['nome']??null,100)]; break;
                case 'turmas':
                    $data=['codperiodo'=>Input::id($d['codperiodo']??null),'idcurso'=>Input::id($d['idcurso']??null),'idturno'=>Input::id($d['idturno']??null),
                        'codigo'=>Input::text($d['codigo']??null,30),'nome'=>Input::text($d['nome']??null,100),'capacidade'=>Input::id($d['capacidade']??null)];
                    $data['idplano']=empty($d['idplano'])?null:Input::id($d['idplano']);
                    $this->validateClassPlan($data);$data['idturma_proxima']=$this->nextClass($d,(int)$data['codperiodo']);
                    if ($data['capacidade']>10000) { throw new RuleViolation('Capacidade acima do limite.'); } break;
            }
            $id=$this->db->insert($table,$data);
            $account=[];
            if($table==='pessoas') { $account=$this->accounts->ensure($id,$d['user_login']??null); }
            if($table==='alunos') { $this->accounts->ensure((int)$data['codpessoa']); }
            $this->db->audit($table,$id,'criar',null,$data,$key);
            return ['id'=>(string)$id]+$account;
    }
    public function link(int $student,array $data,string $key): array
    {
        return $this->db->atomic(fn()=>$this->ops->run($key,'vincular',['idaluno'=>$student]+$data,function()use($student,$data,$key){
            $this->db->get('alunos',$student,true);
            $person=Input::id($data['codpessoa_responsavel']??null);
            if (!(int)$this->db->get('pessoas',$person)['ativo']) { throw new RuleViolation('Pessoa inativa.'); }
            $parent=Input::text($data['parentesco']??null,30);
            if (!array_key_exists($parent,Relationships::LABELS)) { throw new RuleViolation('Parentesco inválido.'); }
            $flags=[];
            foreach (['responsavel_academico','responsavel_financeiro','pode_rematricular'] as $f) {
                if (isset($data[$f]) && !in_array($data[$f],[0,1,'0','1',true,false],true)) { throw new RuleViolation('Atribuição inválida.'); }
                $flags[$f]=empty($data[$f])?0:1;
            }
            $t=$this->db->table('aluno_responsaveis');
            $links=$this->db->rows("SELECT * FROM $t WHERE idaluno=%d AND fim_vigencia IS NULL FOR UPDATE",[$student]);
            $existing=null;
            foreach ($links as $v) {
                if ((int)$v['codpessoa_responsavel']===$person) { $existing=$v; }
                if ($flags['responsavel_financeiro'] && (int)$v['responsavel_financeiro'] && (int)$v['codpessoa_responsavel']!==$person) { throw new RuleViolation('Já existe um responsável financeiro. Use Trocar responsável financeiro na ficha para transferir os saldos com segurança.'); }
            }
            if ($existing) {
                Access::requireAdmin();
                if ((int)$existing['responsavel_financeiro'] && !$flags['responsavel_financeiro']) { throw new RuleViolation('Para retirar a atribuição financeira, escolha primeiro o novo responsável em Trocar responsável financeiro.'); }
                $unchanged=$existing['parentesco']===$parent;
                foreach($flags as $field=>$value) { $unchanged=$unchanged && (int)$existing[$field]===$value; }
                if($unchanged) { return ['idvinculo'=>(string)$existing['idvinculo']]; }
                $this->db->update('aluno_responsaveis',(int)$existing['idvinculo'],['fim_vigencia'=>gmdate('Y-m-d H:i:s')]);
            }
            $id=$this->db->insert('aluno_responsaveis',$flags+['idaluno'=>$student,'codpessoa_responsavel'=>$person,'parentesco'=>$parent,'inicio_vigencia'=>gmdate('Y-m-d H:i:s')]);
            $this->accounts->sync($person);
            $this->db->audit('aluno_responsaveis',$id,'vincular',null,$flags+['idaluno'=>$student,'codpessoa'=>$person],$key);
            return ['idvinculo'=>(string)$id];
        }));
    }
    private function personData(array $d): array
    {
        $d=\EducacionalERP\Domain\CadastroText::normalize($d);
                    $cpf=isset($d['cpf'])?preg_replace('/\D/','',(string)$d['cpf']):null;
                    if ($cpf==='') { $cpf=null; }
                    if ($cpf!==null && !$this->validCpf($cpf)) { throw new RuleViolation('CPF inválido.'); }
                    $email=isset($d['email']) && $d['email']!=='' ? sanitize_email($d['email']) : null;
                    if ($email!==null && !is_email($email)) { throw new RuleViolation('E-mail inválido.'); }
                    $data=['nome'=>Input::text($d['nome']??null),'cpf'=>$cpf,'email'=>$email,'telefone'=>empty($d['telefone'])?null:Input::text($d['telefone'],30),
                        'data_nascimento'=>Input::date($d['data_nascimento']??null)];
                    if ($data['data_nascimento'] && $data['data_nascimento']>Input::today()) { throw new RuleViolation('Nascimento no futuro.'); }
        return \EducacionalERP\Domain\CadastroText::normalize($data+\EducacionalERP\Domain\PersonFields::validate($d))+(new CivilStatus($this->db))->resolve(array_key_exists('idestado_civil',$d)?$d['idestado_civil']:($d['estado_civil']??null));
    }
    public function updatePerson(int $id,array $data,string $key): array
    {
        Access::requireAdmin();
        return $this->db->atomic(fn()=>$this->ops->run($key,'editar_pessoa',['codpessoa'=>$id]+$data,function()use($id,$data,$key){
            $before=$this->db->get('pessoas',$id,true);
            if(isset($data['versao']) && (string)$data['versao']!==$before['versao']) { throw new RuleViolation('O cadastro foi alterado por outra pessoa. Recarregue antes de editar.'); }
            if(array_key_exists('estado_civil',$data)&&!array_key_exists('idestado_civil',$data))$data['idestado_civil']=$data['estado_civil'];
            $valid=$this->personData(array_merge($before,$data));
            if(isset($data['ativo'])) { if(!in_array($data['ativo'],[0,1,'0','1',true,false],true)) { throw new RuleViolation('Situação inválida.'); } $valid['ativo']=(int)$data['ativo']; }
            $this->db->update('pessoas',$id,$valid);
            $account=$this->accounts->ensure($id,$data['user_login']??null);
            $this->db->audit('pessoas',$id,'editar',$before,$valid,$key);
            return ['codpessoa'=>(string)$id]+$account;
        }));
    }
    public function updateOwn(array $data,string $key): array
    {
        $allowed=['nome','cpf','data_nascimento','email','telefone','rg','rua','numero','complemento','bairro','cep','cidade','estado','pais','estado_civil','profissao','religiao','igreja','sexo','foto_attachment_id','idestado_civil','versao'];
        foreach(array_keys($data) as $field)if(!in_array($field,$allowed,true))throw new RuleViolation('Campo não permitido no perfil pessoal: '.$field);
        $user=get_current_user_id();if(!$user)throw new RuleViolation('Entre na sua conta.');
        return $this->db->atomic(fn()=>$this->ops->run($key,'editar_proprio_perfil',['usuario'=>$user]+$data,function()use($data,$key,$user){
            $link=$this->db->row('SELECT codpessoa FROM '.$this->db->table('pessoa_usuarios').' WHERE wp_user_id=%d FOR UPDATE',[$user]);
            if(!$link)throw new RuleViolation('Sua conta não está vinculada a uma pessoa. Procure a secretaria.');
            $id=(int)$link['codpessoa'];$before=$this->db->get('pessoas',$id,true);
            if(!(int)$before['ativo'])throw new RuleViolation('Cadastro inativo.');
            if(!isset($data['versao'])||(string)$data['versao']!==$before['versao'])throw new RuleViolation('Seus dados mudaram. Recarregue o perfil antes de salvar.');
            if(!empty($data['foto_attachment_id'])&&(int)$data['foto_attachment_id']!==(int)$before['foto_attachment_id']&&!\EducacionalERP\Infrastructure\WordPress\Access::isAdmin()&&(int)get_post_field('post_author',(int)$data['foto_attachment_id'])!==$user)throw new RuleViolation('Use uma foto enviada pela sua própria conta.');
            if(array_key_exists('estado_civil',$data)&&!array_key_exists('idestado_civil',$data))$data['idestado_civil']=$data['estado_civil'];
            $valid=$this->personData(array_merge($before,$data));$this->db->update('pessoas',$id,$valid);$this->accounts->sync($id);
            $this->db->audit('pessoas',$id,'editar_proprio_perfil',$before,$valid,$key);
            return ['salvo'=>true,'versao'=>(string)((int)$before['versao']+1)];
        }));
    }
    public function bindUser(array $data,string $key): array
    {
        Access::requireAdmin();
        return $this->db->atomic(fn()=>$this->ops->run($key,'vincular_usuario',$data,function()use($data,$key){
            $person=Input::id($data['codpessoa']??null); $user=Input::id($data['wp_user_id']??null);
            $this->db->get('pessoas',$person,true);
            if (!get_user_by('id',$user)) { throw new RuleViolation('Usuário WordPress inexistente.'); }
            $this->db->insert('pessoa_usuarios',['codpessoa'=>$person,'wp_user_id'=>$user]);
            $this->accounts->sync($person);
            $this->db->audit('pessoa_usuarios',$person,'vincular_usuario',null,['wp_user_id'=>$user],$key);
            return ['codpessoa'=>(string)$person,'wp_user_id'=>(string)$user];
        }));
    }
    public function edit(string $table,int $id,array $data,string $key): array
    {
        Access::requireAdmin();
        if($table==='pessoas') { return $this->updatePerson($id,$data,$key); }
        if(!in_array($table,self::TABLES,true)) { throw new RuleViolation('Cadastro não editável.'); }
        return $this->db->atomic(fn()=>$this->ops->run($key,'editar_'.$table,['id'=>$id]+$data,function()use($table,$id,$data,$key){
            $before=$this->db->get($table,$id,true);
            if(isset($data['versao']) && (string)$data['versao']!==$before['versao']) { throw new RuleViolation('O cadastro mudou. Recarregue a tela antes de salvar.'); }
            $d=\EducacionalERP\Domain\CadastroText::normalize(array_merge($before,$data));$changes=[];
            if($table==='alunos') {
                if(isset($data['codpessoa']) && (int)$data['codpessoa']!==(int)$before['codpessoa']) { throw new RuleViolation('A pessoa do aluno não pode ser substituída, pois possui histórico vinculado.'); }
                $changes['ra']=Input::text($d['ra'],40);$changes['tipo_aluno']=\EducacionalERP\Domain\PersonFields::studentType($d['tipo_aluno']??'Regular');
            } else {
                $changes['codigo']=Input::text($d['codigo'],30);
                if($table==='periodos_letivos') {
                    $changes+=['descricao'=>Input::text($d['descricao']),'data_inicio'=>Input::date($d['data_inicio']),'data_fim'=>Input::date($d['data_fim'])];
                    if($changes['data_fim']<$changes['data_inicio']) { throw new RuleViolation('Datas do período invertidas.'); }
                    if(!in_array($d['status'],['planejado','aberto','encerrado'],true)) { throw new RuleViolation('Situação do período inválida.'); }
                    $changes['status']=$d['status'];
                    $changes['codperiodo_proximo']=$this->nextPeriod($d,$changes['data_inicio'],$id);
                } else { $changes['nome']=Input::text($d['nome'],100); }
            }
            if(in_array($table,['alunos','cursos','turnos','planos_pagamento'],true)) {
                if(!in_array($d['ativo'],[0,1,'0','1',true,false],true)) { throw new RuleViolation('Situação inválida.'); }
                $changes['ativo']=(int)$d['ativo'];
            }
            if($table==='planos_pagamento'){
                $period=Input::id($d['codperiodo']??null);$this->db->get('periodos_letivos',$period);$t=$this->db->table('turmas');
                if($this->db->row("SELECT idturma FROM $t WHERE idplano=%d AND codperiodo<>%d LIMIT 1 FOR UPDATE",[$id,$period]))throw new RuleViolation('Plano vinculado a turma de outro período. Cadastre um plano específico para cada período e ajuste as turmas.');
                $contracts=$this->db->table('contratos');$enrollments=$this->db->table('matriculas');
                if($this->db->row("SELECT c.idcontrato FROM $contracts c JOIN $enrollments m ON m.idmatricula=c.idmatricula WHERE c.idplano=%d AND m.codperiodo<>%d LIMIT 1 FOR UPDATE",[$id,$period]))throw new RuleViolation('O período do plano não pode divergir dos contratos existentes. Cadastre um novo plano.');
                $changes['codperiodo']=$period;
            }
            if($table==='planos_pagamento')$changes['valor_anuidade']=\EducacionalERP\Domain\Money::format(\EducacionalERP\Domain\Money::positive($d['valor_anuidade']));
            if($table==='cursos') { $changes['descricao']=isset($d['descricao'])?strip_tags((string)$d['descricao']):null; }
            if($table==='turnos') {
                foreach(['hora_inicio','hora_fim'] as $field) {
                    $time=$d[$field]??null;
                    if($time!==null && $time!=='' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D',(string)$time)) { throw new RuleViolation('Horário inválido.'); }
                    $changes[$field]=$time?:null;
                }
            }
            if($table==='turmas') {
                $changes['idplano']=empty($d['idplano'])?null:Input::id($d['idplano']);
                
                $changes['capacidade']=Input::id($d['capacidade']);
                if($changes['capacidade']>10000) { throw new RuleViolation('Capacidade acima do limite.'); }
                if(!in_array($d['status'],['ativa','inativa'],true)) { throw new RuleViolation('Situação da turma inválida.'); }
                $changes['status']=$d['status'];
                $m=$this->db->table('matriculas');$used=$this->db->rows("SELECT idmatricula,status FROM $m WHERE idturma_atual=%d FOR UPDATE",[$id]);
                $mov=$this->db->table('matricula_movimentacoes');$history=$this->db->row("SELECT idmovimentacao FROM $mov WHERE idturma_origem=%d OR idturma_destino=%d LIMIT 1 FOR UPDATE",[$id,$id]);
                if($changes['capacidade']<count(array_filter($used,fn($r)=>$r['status']==='ativa'))) { throw new RuleViolation('A capacidade não pode ser menor que a quantidade de alunos ativos.'); }
                foreach(['codperiodo','idcurso','idturno'] as $field) {
                    $changes[$field]=Input::id($d[$field]);
                    if(($used||$history) && $changes[$field]!== (int)$before[$field]) { throw new RuleViolation('Turma com histórico: mantenha período, curso e turno. Cadastre a nova turma e transfira os alunos.'); }
                }
                $this->validateClassPlan($changes);$changes['idturma_proxima']=$this->nextClass($d,$changes['codperiodo'],$id);
                if($changes['codperiodo']!==(int)$before['codperiodo']||$changes['idcurso']!==(int)$before['idcurso']){
                    $t=$this->db->table('turmas');if($this->db->row("SELECT idturma FROM $t WHERE idturma_proxima=%d LIMIT 1 FOR UPDATE",[$id]))throw new RuleViolation('Esta turma é destino de rematrícula. Remova o vínculo de origem antes de alterar curso ou período.');
                }
                $ot=$this->db->table('oferta_turmas');$o=$this->db->table('ofertas_rematricula');
                $offers=$this->db->rows("SELECT o.codperiodo_destino,o.idcurso_destino FROM $ot ot JOIN $o o ON o.idoferta=ot.idoferta WHERE ot.idturma=%d",[$id]);
                foreach($offers as $offer) { if((int)$offer['codperiodo_destino']!==$changes['codperiodo'] || (int)$offer['idcurso_destino']!==$changes['idcurso']) { throw new RuleViolation('A turma está associada a uma oferta incompatível com essa alteração.'); } }
            }
            $this->db->update($table,$id,$changes);
            if($table==='alunos') { $this->accounts->sync((int)$before['codpessoa']); }
            $this->db->audit($table,$id,'editar',$before,$changes,$key);
            return ['id'=>(string)$id,'versao'=>(string)((int)$before['versao']+1)];
        }));
    }
    private function validateClassPlan(array $d):void
    {
        if(empty($d['idplano']))return;
        $plan=$this->db->get('planos_pagamento',(int)$d['idplano'],true);
        if(empty($plan['codperiodo'])||(int)$plan['codperiodo']!==(int)$d['codperiodo'])throw new RuleViolation('O plano de pagamento deve pertencer ao mesmo período letivo da turma.');
    }
    private function nextPeriod(array $d,string $start,int $id=0):?int
    {
        $next=empty($d['codperiodo_proximo'])?null:Input::id($d['codperiodo_proximo']);
        if($next){
            if($next===$id)throw new RuleViolation('O próximo período deve ser diferente do atual.');
            $target=$this->db->get('periodos_letivos',$next,true);
            if($target['data_inicio']<=$start)throw new RuleViolation('O próximo período deve iniciar depois do período atual.');
        }
        if($id){
            $p=$this->db->table('periodos_letivos');$t=$this->db->table('turmas');
            if($this->db->row("SELECT codperiodo FROM $p WHERE codperiodo_proximo=%d AND data_inicio>=%s LIMIT 1 FOR UPDATE",[$id,$start]))throw new RuleViolation('A data inicial invalidaria um período que aponta para este destino.');
            if($this->db->row("SELECT a.idturma FROM $t a JOIN $t b ON b.idturma=a.idturma_proxima WHERE a.codperiodo=%d AND b.codperiodo<>%d LIMIT 1 FOR UPDATE",[$id,$next??0]))throw new RuleViolation('Existem próximas turmas de outro período. Remova esses destinos antes de alterar o próximo período.');
        }
        return $next;
    }
    private function nextClass(array $d,int $period,int $id=0):?int
    {
        if(empty($d['idturma_proxima']))return null;
        $next=Input::id($d['idturma_proxima']);if($next===$id)throw new RuleViolation('A próxima turma deve ser diferente da atual.');
        $row=$this->db->get('turmas',$next,true);$from=$this->db->get('periodos_letivos',$period);$to=$this->db->get('periodos_letivos',(int)$row['codperiodo']);
        if((int)($from['codperiodo_proximo']??0)!==(int)$row['codperiodo'])throw new RuleViolation('A próxima turma deve pertencer ao próximo período configurado no cadastro do período letivo.');
        if($row['status']!=='ativa'||$to['data_inicio']<=$from['data_inicio'])throw new RuleViolation('Selecione uma próxima turma ativa de um período posterior.');
        return $next;
    }
    private function validCpf(string $cpf): bool
    {
        if (!preg_match('/^\d{11}$/D',$cpf) || preg_match('/^(\d)\1{10}$/D',$cpf)) { return false; }
        for ($t=9;$t<11;$t++) {
            $sum=0; for($i=0;$i<$t;$i++) { $sum+=(int)$cpf[$i]*(($t+1)-$i); }
            $digit=(10*$sum)%11; if ($digit===10) { $digit=0; }
            if ((int)$cpf[$t]!==$digit) { return false; }
        }
        return true;
    }
}
