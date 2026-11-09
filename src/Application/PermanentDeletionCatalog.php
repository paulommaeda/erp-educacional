<?php
declare(strict_types=1);
namespace EducacionalERP\Application;
use EducacionalERP\Infrastructure\Database\Database;
use EducacionalERP\Infrastructure\WordPress\Access;
use EducacionalERP\Domain\{Input,RuleViolation};
/** Only bundled ERP tables and their complete primary keys can be addressed. */
final class PermanentDeletionCatalog
{
    public const LABELS=['coligadas'=>'Coligadas','pessoas'=>'Pessoas','pessoa_enderecos'=>'Endereços','pessoa_usuarios'=>'Vínculos de usuários','alunos'=>'Alunos','aluno_responsaveis'=>'Responsáveis dos alunos','aluno_retiradas'=>'Autorizações de retirada','periodos_letivos'=>'Períodos letivos','cursos'=>'Cursos','turnos'=>'Turnos','turmas'=>'Turmas','matriculas'=>'Matrículas','matricula_movimentacoes'=>'Movimentações de matrícula','ofertas_rematricula'=>'Ofertas de rematrícula','oferta_turmas'=>'Turmas das ofertas','rematriculas'=>'Rematrículas','modelos_contrato'=>'Modelos de contrato','contratos'=>'Contratos','parcelas'=>'Parcelas','lancamentos'=>'Lançamentos financeiros','baixas'=>'Pagamentos / baixas','baixa_estornos'=>'Estornos','lancamento_ajustes'=>'Ajustes financeiros','trocas_responsavel'=>'Trocas de responsável','troca_contratos'=>'Contratos das trocas','troca_lancamentos'=>'Lançamentos das trocas','auditoria'=>'Auditoria','operacoes'=>'Operações registradas','aluno_periodos'=>'Vínculos aos períodos','planos_pagamento'=>'Planos de pagamento','estados_civis'=>'Estados civis','numeracao_pessoas'=>'Numeração de pessoas','contrato_descontos'=>'Bolsas e descontos','desconto_lancamentos'=>'Descontos dos lançamentos','tipos_disciplina'=>'Tipos de disciplina','historicos_anteriores'=>'Históricos escolares','historico_anos'=>'Anos dos históricos','historico_disciplinas'=>'Disciplinas dos históricos','chat_canais'=>'Canais do chat','chat_membros'=>'Participantes do chat','chat_conversas'=>'Conversas','chat_mensagens'=>'Mensagens','chat_anexos'=>'Anexos do chat','chat_leituras'=>'Leituras do chat','campos_adicionais'=>'Campos adicionais','pessoa_campos_adicionais'=>'Valores dos campos adicionais','grupos_campos'=>'Grupos de campos','api_requerimentos'=>'Requerimentos importados da API','disciplinas'=>'Disciplinas','grades_curriculares'=>'Grades curriculares','grade_disciplinas'=>'Disciplinas das grades','turma_grades'=>'Grades das turmas','professores'=>'Professores','professor_disciplinas'=>'Disciplinas dos professores','professor_turmas'=>'Turmas dos professores','professor_valores_hora'=>'Valores de hora/aula','subtipos_disciplina'=>'Subtipos de disciplina','tipos_documento_aluno'=>'Tipos de documento','aluno_documentos'=>'Documentos dos alunos','educbank_fila'=>'Envios Educbank','educbank_retornos'=>'Retornos Educbank','educbank_recebimentos'=>'Recebimentos Educbank','desconto_recalculos'=>'Recálculos de descontos','desconto_indicados'=>'Indicações de alunos','requerimentos_publicos'=>'Requerimentos públicos','requerimento_arquivos'=>'Arquivos dos requerimentos','registros_preservados'=>'Registros preservados do backup','usuario_acessos'=>'Histórico de acessos','exclusoes_definitivas'=>'Comprovantes de exclusão definitiva'];
    public function __construct(private Database $db){}
    public function types():array {Access::requireAdmin();$items=[];foreach($this->db->schema() as $type=>$meta)$items[]=['tipo'=>$type,'nome'=>self::label($type)];usort($items,fn($a,$b)=>strcmp($a['nome'],$b['nome']));return $items;}
    public static function label(string $type):string{return self::LABELS[$type]??ucfirst(str_replace('_',' ',$type));}
    public function type(mixed $type):string {if(!is_string($type)||!isset($this->db->schema()[$type]))throw new RuleViolation('Tipo de dado inválido.');return $type;}
    public function key(string $type,mixed $key):array
    {
        if(!is_array($key)||array_is_list($key))throw new RuleViolation('Informe o registro que deseja excluir.');$result=[];
        foreach($this->db->schema()[$type]['pk'] as $col)$result[$col]=(string)Input::id($key[$col]??null);
        if(count($key)!==count($result))throw new RuleViolation('Chave do registro inválida.');return $result;
    }
    public function where(array $key,string $alias=''):array {return [implode(' AND ',array_map(fn($c)=>$alias.'`'.$c.'`=%s',array_keys($key))),array_values($key)];}
    public function rowKey(string $type,array $row):array {$key=[];foreach($this->db->schema()[$type]['pk'] as $col)$key[$col]=(string)$row[$col];return $key;}
    public function title(string $type,array $row):string
    {
        $title=$row['nome']??$row['nome_aluno']??$row['descricao']??$row['numero']??$row['protocolo']??$row['codigo']??$row['user_login_snapshot']??'';
        if(in_array($type,['alunos','professores'],true)){$p=$this->db->row('SELECT nome FROM '.$this->db->table('pessoas').' WHERE codpessoa=%s',[$row['codpessoa']]);$title=($p['nome']??'Pessoa #'.$row['codpessoa']).(!empty($row['ra'])?' · RA '.$row['ra']:'');}
        return (string)$title;
    }
    public function listing(array $params):array
    {
        Access::requireAdmin();$type=$this->type($params['tipo']??null);$meta=$this->db->schema()[$type];$page=max(1,min(100000,(int)($params['pagina']??1)));$search=mb_substr(trim((string)($params['busca']??'')),0,100);$cols=array_values(array_unique([...$meta['pk'],...array_intersect(['nome','nome_aluno','descricao','numero','protocolo','codigo','ra','codpessoa','codcoligada','user_login_snapshot','versao'],array_keys($meta['columns']))]));$where='1=1';$args=[];
        if($search!==''){$parts=[];foreach($cols as $col){$parts[]='CAST(t.`'.$col.'` AS CHAR) LIKE %s';$args[]='%'.$this->db->wp->esc_like($search).'%';}if(in_array($type,['alunos','professores'],true)){$parts[]='EXISTS (SELECT 1 FROM '.$this->db->table('pessoas').' p WHERE p.codpessoa=t.codpessoa AND p.nome LIKE %s)';$args[]='%'.$this->db->wp->esc_like($search).'%';}$where='('.implode(' OR ',$parts).')';}
        $rows=$this->db->rows('SELECT '.implode(',',array_map(fn($c)=>'t.`'.$c.'`',$cols)).' FROM '.$this->db->table($type).' t WHERE '.$where.' ORDER BY '.implode(',',$meta['pk']).' LIMIT 21 OFFSET %d',[...$args,($page-1)*20]);$items=[];
        foreach(array_slice($rows,0,20) as $row)$items[]=['chave'=>$this->rowKey($type,$row),'rotulo'=>$this->title($type,$row),'coligada'=>$row['codcoligada']??null];
        return ['items'=>$items,'pagina'=>$page,'mais'=>count($rows)>20];
    }
}
