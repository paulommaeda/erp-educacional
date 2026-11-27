(() => {
  'use strict';
  const root=document.querySelector('[data-erp-contracts]');if(!root)return;
  const canContracts=!!(EDERP.caps?.admin||EDERP.caps?.contracts)&&root.dataset.erpContracts!=='discounts',canDiscounts=!!(EDERP.caps?.admin||EDERP.caps?.discounts);if(!canContracts&&!canDiscounts){root.textContent='Sem permissão para gerenciar contratos ou descontos.';return;}
  const canReprice=!!(EDERP.caps?.admin||EDERP.caps?.contracts);
  const h=(tag,text,cls)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=text;if(cls)n.className=cls;return n;};
  const button=(text,fn,cls='')=>{const n=h('button',text,cls);n.type='button';n.onclick=fn;return n;};
  const money=v=>new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(Number(v||0));
  const date=v=>v?String(v).slice(0,10).split('-').reverse().join('/'):'—';
  const month=v=>v?String(v).slice(0,7).split('-').reverse().join('/'):'—';
  const canChangeCompetence=c=>canReprice&&c.status==='ativo'&&(!Number(c.parcelas_geradas)||EDERP.caps?.admin||EDERP.caps?.editFinance);
  const uuid=()=>crypto.randomUUID?.()||Array.from(crypto.getRandomValues(new Uint8Array(24)),x=>x.toString(16).padStart(2,'0')).join('');
  const field=(name,label,value='',type='text',required=true)=>{const node=h('label',label),input=h(type==='textarea'?'textarea':'input');if(type!=='textarea')input.type=type;input.name=name;input.value=value??'';input.required=required;if(type==='number'){input.min=1;input.max=120;input.step=1;}node.append(input);return {node,input};};
  const choice=(name,label,items)=>{const node=h('label',label),input=h('select');input.name=name;for(const [v,t]of items)input.append(new Option(t,v));node.append(input);return {node,input};};
  const feedback=()=>{const p=h('p','','erp-contract-feedback');p.setAttribute('role','status');return p;};
  const link=(text,view,params={})=>{const a=h('a',text,'erp-link-button'),u=new URL(EDERP.front?EDERP.portal:EDERP.admin,location.href);u.searchParams.set(EDERP.front?'erp_tela':'page',EDERP.front?view:'ederp-'+view);for(const [key,value]of Object.entries(params))u.searchParams.set(key,value);a.href=u.href;return a;};
  function apiUrl(path){
    const base=new URL(EDERP.root,location.href);
    if(!base.searchParams.has('rest_route'))return new URL(EDERP.root+path,location.href);
    const [route,query]=path.split('?',2);
    base.searchParams.set('rest_route',base.searchParams.get('rest_route')+route);
    for(const [name,value] of new URLSearchParams(query))base.searchParams.append(name,value);
    return base;
  }
  async function api(path,data,key){const r=await fetch(apiUrl(path),{method:data?'POST':'GET',credentials:'same-origin',cache:'no-store',headers:{'X-WP-Nonce':EDERP.nonce,...(data?{'Content-Type':'application/json','Idempotency-Key':key||uuid()}:{})},...(data?{body:JSON.stringify(data)}:{})});let body;try{body=await r.json();}catch{throw Error('O servidor não respondeu como esperado. Tente novamente.');}if(!r.ok)throw Error(body.message||'Não foi possível concluir.');return body;}
  const contractText=(text,format)=>window.EDERPContractDocument?.render(text,format)||h('pre',text,'erp-contract-text');
  const htmlHint='Aceita HTML de formatação: parágrafos, títulos, negrito, itálico, listas e tabelas, com estilos de fonte, alinhamento e borda. Use [contrato_valor_original] e [contrato_parcelamento_original] para os valores antes de qualquer bolsa ou desconto. Use [contrato_dia_vencimento] para mostrar somente o dia. A logo e o rodapé são incluídos na impressão a partir das configurações do colégio. Shortcodes externos ativos aparecem na lista de inserção. Outros marcadores ficam entre colchetes na prévia e no contrato.';
  const tuitionClause='A CONTRATANTE pagará a anuidade escolar de [contrato_valor_original], dividida em [contrato_parcelamento_original].';
  const defaultModelText=[
    'CONTRATO DE PRESTAÇÃO DE SERVIÇOS EDUCACIONAIS',
    '[colegio_nome], CNPJ [colegio_cnpj], e [responsavel_nome], CPF [responsavel_cpf], responsável pelo(a) aluno(a) [aluno_nome], RA [aluno_ra], celebram o contrato [contrato_numero] para o período [periodo_codigo], turma [turma_nome].',
    tuitionClause+' Vencimento no dia [contrato_dia_vencimento] de cada mês. Primeiro vencimento: [contrato_primeiro_vencimento].',
    '[bolsas_do_aluno]',
    '[contrato_data]'
  ].join('\n\n');
  let models=[],tokens={},externalTokens=[],page=1,generation=0,active='contracts',rows=[];
  const params=new URLSearchParams(location.search),student=params.get('aluno');
  root.replaceChildren(h('h1',canContracts?'Contratos':'Bolsas e descontos dos contratos'),h('p',canContracts?'Consulte os contratos dos alunos e cadastre os modelos usados pela secretaria.':'Busque o aluno, RA ou contrato para aplicar e gerenciar bolsas, descontos e indicações.'));
  const notice=feedback(),tabs=h('nav',undefined,'erp-tabs'),listTab=button('Contratos dos alunos',()=>switchTab('contracts')),modelsTab=button('Modelos de contrato',()=>switchTab('models')),listPanel=h('section'),modelPanel=h('section');
  tabs.setAttribute('aria-label','Áreas de contratos');tabs.append(listTab);if(canContracts)tabs.append(modelsTab);else tabs.hidden=true;root.append(tabs,notice,listPanel,modelPanel);
  const searchForm=h('form',undefined,'erp-contract-filters');
  const search=field('search','Buscar por aluno, RA ou contrato','','search',false);
  const status=choice('status','Situação',[['','Todas'],['ativo','Ativos'],['pendente','Aguardando parcelas'],['cancelado','Cancelados']]);
  const signatureFilter=choice('assinatura','Assinatura',[['','Todos'],['assinado','Assinados'],['nao_assinado','Não assinados']]);
  signatureFilter.input.setAttribute('aria-label','Assinatura');
  const find=h('button','Buscar');find.type='submit';
  search.input.maxLength=191;searchForm.append(search.node,status.node);
  searchForm.append(signatureFilter.node);
  searchForm.append(find);

  const headerTools=h('div',undefined,'erp-contract-toolbar'),list=h('div');
  const pagination=h('nav',undefined,'erp-contract-pagination'),pageInfo=h('span');
  const previous=button('Anterior',()=>{page--;load();}),next=button('Próxima',()=>{page++;load();});
  pagination.setAttribute('aria-label','Páginas de contratos');pagination.append(previous,pageInfo,next);
  if(student)headerTools.append(link('Ver todos os alunos',canContracts?'contratos':'bolsas'));
  if(canDiscounts&&window.EDERPScholarships)headerTools.append(button('Bolsas por aluno',()=>window.EDERPScholarships.chooseStudent(load)));
  if(canContracts&&EDERP.caps?.admin)headerTools.append(button('Configurar assinatura eletrônica',signatureSettings));
  if(headerTools.childElementCount)listPanel.append(headerTools);
  listPanel.append(searchForm);

  const selected=new Map(),limit=100,selectionBar=h('div',undefined,'erp-contract-toolbar erp-contract-batch');
  const pageCheck=h('input'),pageLabel=h('label',undefined,'erp-contract-selection'),selectionInfo=feedback();
  const sendSelected=button('Enviar para assinatura',()=>openBatch(true));
  const checkSelected=button('Conferir assinaturas',()=>openBatch(false));
  const clear=button('Limpar seleção',()=>{selected.clear();updateSelection();},'erp-secondary');
  pageCheck.type='checkbox';
  pageCheck.setAttribute('aria-label','Selecionar contratos desta página');
  pageCheck.title='Selecionar contratos desta página';
  pageLabel.append(pageCheck);
  if(canContracts){selectionBar.append(pageLabel,sendSelected,checkSelected,clear,selectionInfo);listPanel.append(selectionBar);}
  listPanel.append(list,pagination);
  const signatureLabels={preparado:'Envio a confirmar',draft:'Rascunho',sent:'Convite solicitado',viewed:'Visualizado',signed:'Assinado',cancelled:'Cancelado',expired:'Expirado',rejeitado:'Envio recusado'};
  const pendingSignature=new Set(['preparado','draft','sent','viewed']);
  const canSend=c=>c.status==='ativo'&&!c.assinado_em&&!['draft','sent','viewed'].includes(c.envio_assinatura?.situacao);
  const canCheck=c=>!!c.envio_assinatura&&c.envio_assinatura.situacao!=='rejeitado';
  const selectable=c=>canSend(c)||canCheck(c);
  const visibleEligible=()=>rows.filter(selectable);

  function updateSelection(){
    if(!canContracts)return;
    const contracts=[...selected.values()],eligible=visibleEligible();
    const onPage=eligible.filter(c=>selected.has(String(c.idcontrato))).length;
    pageCheck.checked=!!eligible.length&&onPage===eligible.length;
    pageCheck.indeterminate=onPage>0&&onPage<eligible.length;
    pageCheck.disabled=!eligible.length;
    for(const input of list.querySelectorAll('[data-batch-contract]')){
      input.checked=selected.has(input.dataset.batchContract);
    }
    selectionInfo.textContent=contracts.length?(contracts.length+' selecionado(s) · '+contracts.filter(canSend).length+' para envio · '+contracts.filter(canCheck).length+' para conferência'):'';
    sendSelected.disabled=!contracts.some(canSend);
    checkSelected.disabled=!contracts.some(canCheck);
    clear.disabled=!contracts.length;
  }
  function selectContract(c,checked){
    const id=String(c.idcontrato);
    if(!checked){selected.delete(id);return;}
    if(!selected.has(id)&&selected.size>=limit){
      notice.textContent='Selecione até '+limit+' contratos por lote.';
      return;
    }
    selected.set(id,c);
  }
  pageCheck.onchange=()=>{for(const c of visibleEligible())selectContract(c,pageCheck.checked);updateSelection();};
  function applyFilters(){selected.clear();notice.textContent='';page=1;load();}
  searchForm.onsubmit=e=>{e.preventDefault();applyFilters();};
  status.input.onchange=signatureFilter.input.onchange=applyFilters;

  function switchTab(view){if(view==='models'&&!canContracts)view='contracts';active=view;listPanel.hidden=view!=='contracts';modelPanel.hidden=view!=='models';listTab.setAttribute('aria-pressed',String(view==='contracts'));modelsTab.setAttribute('aria-pressed',String(view==='models'));listTab.classList.toggle('active',view==='contracts');modelsTab.classList.toggle('active',view==='models');if(view==='models')loadModels();else load();}

  async function load(){
    const turn=++generation;
    rows=[];list.replaceChildren(h('p','Carregando contratos...'));previous.disabled=next.disabled=find.disabled=true;updateSelection();
    try{
      const q=new URLSearchParams({page:String(page),search:search.input.value,status:status.input.value,codperiodo:String(EDERP.period||EDERP.currentPeriod||'todos')});
      if(signatureFilter.input.value)q.set('assinatura',signatureFilter.input.value);
      if(!canContracts)q.set('visao','bolsas');
      if(student)q.set('idaluno',student);
      const d=await api('contratos?'+q);
      if(turn!==generation)return;
      rows=d.items;
      list.replaceChildren(rows.length?contractTable():h('p','Nenhum contrato encontrado. Confira o período selecionado ou refine a busca.'));
      pageInfo.textContent=d.total+' contratos · Página '+page;
      previous.disabled=page<=1;next.disabled=page*20>=d.total;
    }catch(e){if(turn===generation){list.replaceChildren(h('p',e.message));pageInfo.textContent='';}}
    finally{if(turn===generation){find.disabled=false;updateSelection();}}
  }

  function contractTable(){
    const table=h('table',undefined,'erp-contract-table'),head=h('thead'),tr=h('tr');
    const columns=canContracts?['Contrato e aluno','Período e turma','Condições','Situação','Assinado','Ações']:
      ['Contrato e aluno','Período e turma','Condições','Venc.','Pontualidade','Descontos incondicionais','Assinado'];
    for(const title of columns){
      const heading=h('th',title,title==='Assinado'?'erp-contract-signed':'');
      heading.scope='col';tr.append(heading);
    }
    head.append(tr);table.append(head);const body=h('tbody');
    for(const c of rows){
      const row=h('tr'),who=h('td'),school=h('td'),terms=h('td'),state=h('td'),signed=h('td',undefined,'erp-contract-signed'),actions=h('td',undefined,'erp-contract-row-menu');
      who.dataset.label='Contrato e aluno';
      if(canContracts&&selectable(c)){
        const label=h('label',undefined,'erp-contract-selection'),input=h('input');
        input.type='checkbox';input.dataset.batchContract=String(c.idcontrato);
        input.setAttribute('aria-label','Selecionar contrato '+c.numero);
        input.onchange=()=>{selectContract(c,input.checked);updateSelection();};
        label.append(input,h('strong',c.numero));who.append(label);
      }else if(canContracts)who.append(h('strong',c.numero));
      else who.append(button(c.numero,()=>openSummary(c),'erp-contract-summary-link'));
      who.append(h('span',c.aluno),h('small','RA '+c.ra));
      school.dataset.label='Período e turma';school.append(h('span',c.periodo),h('small',c.turma));
      terms.dataset.label='Condições';terms.append(h('strong',money(c.valor_liquido_total)),h('small',c.quantidade_parcelas+' parcelas · '+date(c.primeiro_vencimento)));
      state.dataset.label='Situação';state.append(h('span',c.status==='cancelado'?'Cancelado':Number(c.parcelas_geradas)?'Parcelas geradas':'Aguardando parcelas','erp-contract-badge'));
      const envio=c.envio_assinatura;
      if(envio&&envio.situacao!=='signed')state.append(h('small',signatureLabels[envio.situacao]));

      signed.dataset.label='Assinado';
      const signatureCheck=h('div',undefined,'erp-contract-signature-check'),check=h('input');
      check.type='checkbox';check.checked=!!c.assinado_em;check.disabled=!!c.assinado_em||!canContracts;
      check.setAttribute('aria-label','Contrato '+c.numero+' assinado');
      check.onchange=()=>{check.checked=false;openContract(c.idcontrato);};
      signatureCheck.append(check);signed.append(signatureCheck);

      actions.dataset.label='Ações';
      const actionsForContract=[];
      if(canContracts){
        actionsForContract.push(button('Ver contrato',()=>openContract(c.idcontrato)));
        if(c.status==='ativo')actionsForContract.push(button('Editar',()=>editContract(c.idcontrato)));
        if(c.status==='ativo')actionsForContract.push(button('Regerar ou trocar plano',()=>openRepricing(c)));
        if(canChangeCompetence(c))actionsForContract.push(button('Ajustar competência',()=>openCompetence(c)));
        actionsForContract.push(button('Excluir',()=>removeContract(c.idcontrato),'erp-contract-danger'));
      }
      if(canContracts&&canDiscounts&&c.status==='ativo')actionsForContract.push(button('Bolsas e descontos',()=>window.EDERPContractDiscounts.open(c.idaluno,c.idcontrato,load).catch(e=>{notice.textContent=e.message;})));
      if(actionsForContract.length)actions.append(window.EDERPActionMenu.create(actionsForContract,root,'Ações do contrato '+c.numero));
      if(canContracts)row.append(who,school,terms,state,signed,actions);
      else{
        const due=h('td',c.dia_vencimento??'—'),punctuality=h('td'),discounts=h('td');
        due.dataset.label='Venc.';
        punctuality.dataset.label='Pontualidade';discounts.dataset.label='Descontos incondicionais';
        terms.replaceChildren(h('strong',money(c.valor_original_contrato)+' original'),
          h('small',c.quantidade_parcelas+' parcelas · '+amountRange(c,'original_parcela')+' original'),h('small',money(c.valor_liquido_total)+' líquidos'));
        punctuality.append(h('strong',c.pontualidade_total===null?'—':money(c.pontualidade_total)+' total'),h('small',amountRange(c,'pontualidade')+' por parcela'));
        discounts.append(h('strong',money(c.desconto_incondicional_total)+' total'),h('small',amountRange(c,'desconto_parcela')+' por parcela'));
        row.append(who,school,terms,due,punctuality,discounts,signed);
      }
      body.append(row);
    }
    table.append(body);return table;
  }

  function openBatch(send){
    const contracts=[...selected.values()].filter(send?canSend:canCheck);
    if(!contracts.length)return;
    const ignored=selected.size-contracts.length;
    const m=modal(send?'Enviar contratos para assinatura':'Conferir assinaturas dos contratos');
    const candidates=h('ul',undefined,'erp-contract-batch-list');
    for(const c of contracts)candidates.append(h('li',c.numero+' · '+c.aluno));
    const confirm=button(send?'Confirmar envio':'Confirmar conferência',runBatch);
    m.content.append(h('p',send?'O ERP solicitará o convite por e-mail para o responsável financeiro de cada contrato abaixo.':'O ERP vai consultar a última revisão enviada de cada contrato abaixo.'));
    if(ignored)m.content.append(h('p',ignored+' contrato(s) selecionado(s) não se aplicam a esta ação.'));
    m.content.append(candidates,confirm);

    async function runBatch(){
      if(m.dialog.dataset.busy==='1')return;
      m.dialog.dataset.busy='1';m.close.disabled=true;
      const results=h('ul',undefined,'erp-contract-batch-list');
      m.content.replaceChildren(results);
      let completed=0,failed=0;
      try{
        for(const c of contracts){
          m.status.textContent='Processando '+(completed+failed+1)+' de '+contracts.length+'…';
          const base='contratos/'+c.idcontrato+'/assinatura';
          try{
            const response=send?await api(base+'/enviar',{versao:c.versao}):await api(base+'/'+c.envio_assinatura.idenvio+'/consultar',{});
            const envio=send?response.items[0]:response.items.find(row=>String(row.idenvio)===String(c.envio_assinatura.idenvio));
            if(!envio)throw Error('O serviço não retornou a revisão consultada.');
            const label=envio.situacao==='signed'&&!Number(envio.vinculado)?'Revisão anterior assinada':signatureLabels[envio.situacao];
            results.append(h('li',c.numero+' · '+(label||'Processado')));
            completed++;
            selected.delete(String(c.idcontrato));
          }catch(error){
            failed++;
            results.append(h('li',c.numero+' · '+error.message,'erp-contract-danger'));
          }
        }
        const message=completed+' processado(s)'+(failed?' e '+failed+' com erro':'')+'.';
        m.status.textContent=notice.textContent=message;
        await load();
      }finally{
        m.dialog.dataset.busy='0';m.close.disabled=false;
      }
    }
  }
  function modal(title){const dialog=h('dialog',undefined,'journey-confirm ederp erp-contract-dialog'),heading=h('h2',title),close=button('Fechar',()=>dialog.close(),'erp-secondary'),content=h('div'),status=feedback();heading.id='erp-contract-dialog-'+uuid();dialog.setAttribute('aria-labelledby',heading.id);const focused=document.activeElement,opener=focused?.closest('.erp-enrollment-actions')?.querySelector('summary')||focused,header=h('header',undefined,'erp-contract-dialog-header');header.append(heading,close);dialog.append(header,content,status);dialog.onclose=()=>{dialog.remove();if(opener?.isConnected)opener.focus();};dialog.addEventListener('cancel',e=>{if(dialog.dataset.busy==='1')e.preventDefault();});document.body.append(dialog);dialog.showModal();return {dialog,content,status,close};}
  function summary(c){const box=h('dl',undefined,'erp-contract-summary erp-record-grid erp-record-panel');for(const [label,value,wide]of [['Aluno',c.aluno+' · RA '+c.ra,true],['Período e turma',c.periodo+' · '+c.turma],['Responsável financeiro',c.responsavel],['Valor líquido',money(c.valor_liquido_total)],['Parcelamento',c.quantidade_parcelas+' parcelas · '+date(c.primeiro_vencimento)]]){const item=h('div',undefined,wide?'erp-record-wide':'');item.append(h('dt',label),h('dd',value||'Não informado'));box.append(item);}return box;}
  function amountRange(c,field){
    const min=c[field+'_min'],max=c[field+'_max'];
    return min==null?'—':money(min)+(min===max?'':' a '+money(max));
  }
  async function openSummary(row){
    const m=modal('Resumo do contrato '+row.numero);
    m.status.textContent='Carregando resumo...';
    try{
      const c=await api('contratos/'+row.idcontrato+'/resumo');
      if(!m.dialog.isConnected)return;
      m.status.textContent='';
      const facts=summary(c);
      for(const [label,value] of [
        ['Data do contrato',date(c.data_contrato)],['Valor original',money(c.valor_original_total)],
        ['Descontos incondicionais',money(c.desconto_incondicional_total)+' · '+amountRange(c,'desconto_parcela')+' por parcela'],
        ['Pontualidade',(c.pontualidade_total===null?'—':money(c.pontualidade_total))+' · '+amountRange(c,'pontualidade')+' por parcela'],
        ['Valor pago',money(c.valor_pago)],['Saldo em aberto',money(c.saldo_aberto)],
        ['Situação',c.status==='cancelado'?'Cancelado':Number(c.parcelas_geradas)?'Parcelas geradas':'Aguardando parcelas'],
        ['Assinado',c.assinado_em?'Sim · '+date(c.assinado_em):'Não']
      ]){
        const item=h('div');item.append(h('dt',label),h('dd',value));facts.append(item);
      }
      m.content.append(facts);
      if(c.bolsas_aluno?.length&&window.EDERPScholarships)m.content.append(window.EDERPScholarships.contractStudentGrants(c.bolsas_aluno));
      if(c.descontos_previstos?.length){
        const items=h('ul');
        for(const d of c.descontos_previstos)items.append(h('li',d.nome+' · '+money(d.valor)+' por parcela · '+d.parcela_inicio+' a '+d.parcela_fim+(d.descricao?' · '+d.descricao:'')));
        m.content.append(h('h3','Descontos previstos'),items);
      }
      if(c.bolsas_previstas?.length){
        const items=h('ul');
        for(const b of c.bolsas_previstas){
          const value=b.forma_calculo==='percentual'?Number(b.valor).toLocaleString('pt-BR')+'%':money(b.valor);
          items.append(h('li',b.nome_snapshot+' · '+value+' · Parcelas '+b.parcela_inicio+' a '+b.parcela_fim+(b.descricao_snapshot?' · '+b.descricao_snapshot:'')));
        }
        m.content.append(h('h3','Bolsas previstas'),items);
      }
      if(c.parcelas.length){
        const table=h('table',undefined,'erp-contract-table'),head=h('thead'),heading=h('tr'),body=h('tbody');
        const labels=['Parcela e vencimento','Original','Descontos incondicionais','Pontualidade','Líquido','Pago','Saldo e situação','Competência'];
        for(const label of labels){const th=h('th',label);th.scope='col';heading.append(th);}
        const statusLabels={baixado:'Baixado',em_aberto:'Em aberto',vencido:'Vencido'};
        for(const p of c.parcelas){
          const tr=h('tr');
          const values=[p.numero+' · '+date(p.vencimento),money(p.valor_original),money(p.desconto_incondicional),money(p.pontualidade),money(p.valor_liquido),money(p.valor_baixa),money(p.saldo_aberto)+' · '+statusLabels[p.situacao],month(p.competencia)];
          for(const [index,value] of values.entries()){
            const cell=h('td',value);cell.dataset.label=labels[index];
            if(index===2)for(const d of p.descontos_detalhados){
              cell.append(h('small',d.nome+' · '+money(d.valor)+(d.descricao?' · '+d.descricao:'')));
            }
            tr.append(cell);
          }
          body.append(tr);
        }
        head.append(heading);table.append(head,body);m.content.append(h('h3','Parcelas do contrato'),table);
      }
      const actions=h('div',undefined,'erp-contract-toolbar');
      if(c.status==='ativo'&&canDiscounts){
        const edit=button('Gerenciar bolsas e descontos',()=>{
          m.dialog.close();
          window.EDERPContractDiscounts.open(c.idaluno,c.idcontrato,load).catch(e=>{notice.textContent=e.message;});
        });
        actions.append(edit);
      }
      if(c.status==='ativo'&&canReprice){
        actions.append(button('Regerar ou trocar plano',()=>{m.dialog.close();openRepricing(c);}));
      }
      if(canChangeCompetence(c)){
        actions.append(button('Ajustar competência',()=>{m.dialog.close();openCompetence(c);},'erp-secondary'));
      }
      if(actions.childElementCount)m.content.prepend(actions);
    }catch(e){if(m.dialog.isConnected)m.status.textContent=e.message;}
  }
  async function openCompetence(c){
    const m=modal('Ajustar competência · '+c.numero),path='contratos/'+c.idcontrato+'/competencia';
    m.status.textContent='Carregando parcelas...';
    try{
      const data=await api(path);
      if(!m.dialog.isConnected)return;
      m.status.textContent='';
      const form=h('form',undefined,'ederp-form erp-contract-form');
      const scope=choice('parcela','Aplicar a',[['','Sequência mensal do contrato'],...data.parcelas.map(p=>[p.numero,'Parcela '+p.numero+' · '+month(p.competencia)+' · vencimento '+date(p.vencimento)])]);
      const competence=field('competencia','Competência',data.competencia_inicial,'month');
      const reason=field('motivo','Motivo da alteração','','textarea');reason.input.maxLength=2000;
      const note=h('p');
      function sync(){
        const part=data.parcelas.find(p=>String(p.numero)===scope.input.value);
        competence.input.value=part?.competencia??data.competencia_inicial;
        note.textContent=part?'O vencimento, os valores e os pagamentos da parcela ficam preservados.'
          :'Informe a competência da parcela 1. As seguintes avançam um mês por parcela. O ajuste inclui parcelas pagas. Vencimentos, valores, pagamentos e competências de parcelas canceladas ou excluídas ficam preservados.';
      }
      scope.input.addEventListener('change',sync);sync();
      if(data.parcelas.length)form.append(scope.node);
      else if(!data.parcelas_geradas)note.textContent='A competência inicial será usada quando as parcelas forem geradas.';
      form.append(competence.node,note,reason.node);m.content.append(form);
      bind(m,form,path,()=>({versao:data.versao,competencia:competence.input.value,motivo:reason.input.value,
        ...(scope.input.value?{parcela:scope.input.value}:{})}),async result=>{
        notice.textContent=result.competencias_alteradas?result.competencias_alteradas+' competência(s) atualizada(s).':
          result.competencia_inicial_alterada?'Competência inicial salva.':'As competências já estavam atualizadas.';
        await load();
      });
      form.querySelector('button[type=submit]').textContent='Salvar competência';
    }catch(e){if(m.dialog.isConnected)m.status.textContent=e.message;}
  }
  async function openRepricing(c){
    const m=modal('Regerar contrato '+c.numero);
    const path='contratos/'+c.idcontrato+'/regerar';
    m.status.textContent='Carregando planos...';
    try{
      const result=await api(path);
      if(!m.dialog.isConnected)return;
      m.status.textContent='';
      const form=h('form',undefined,'ederp-form erp-contract-form');
      const plan=choice('idplano','Plano de pagamento',[['','Manter plano e recalcular']]);
      const amount=field('desconto_manual','Desconto manual total do contrato (R$)',result.desconto_manual??'');
      const competence=field('competencia_inicial','Nova competência da parcela 1 (opcional)','','month',false);
      const reason=field('motivo','Motivo da alteração','','textarea');
      reason.input.maxLength=2000;amount.input.inputMode='decimal';
      amount.input.readOnly=result.assinado&&result.desconto_manual!==null;
      for(const p of result.planos)plan.input.append(new Option(p.nome+' · '+money(p.valor_anuidade),p.idplano));
      plan.input.disabled=result.assinado;
      if(result.plano)m.content.append(h('p','Plano atual · '+result.plano));
      m.content.append(h('p','O recálculo usa bolsas, descontos cadastrados e ajustes registrados. Mantém a quantidade e os vencimentos. Os valores das parcelas com histórico de baixa, canceladas ou excluídas são preservados.'),form);
      if(result.assinado)m.content.insertBefore(h('p','O contrato assinado permite recálculo financeiro. O texto e o plano ficam vinculados à assinatura existente.'),form);
      if(result.desconto_manual===null)m.content.insertBefore(h('p','Informe o desconto manual acordado neste contrato antigo. Use 0,00 se ele não tiver desconto manual.'),form);
      const confirm=h('label',undefined,'ederp-check'),check=h('input');check.type='checkbox';check.required=true;
      confirm.append(check,h('span','Confirmo a alteração das parcelas disponíveis.'));
      form.append(plan.node,amount.node,competence.node,h('p','Competência inicial atual: '+month(result.competencia_inicial)+'. Preencha o campo para aplicar uma nova sequência mensal, inclusive às parcelas pagas. Se ficar vazio, as competências já registradas serão mantidas.'),reason.node,confirm);
      bind(m,form,path,()=>{
        const selected=result.planos.find(p=>String(p.idplano)===plan.input.value);
        const value=amount.input.value.trim();
        return {versao:result.versao,motivo:reason.input.value,confirmado:check.checked,
          desconto_manual:value.includes(',')?value.replaceAll('.','').replace(',','.'):value,
          ...(competence.input.value?{competencia_inicial:competence.input.value}:{}),
          ...(selected?{idplano:selected.idplano,plano_versao:selected.versao}:{})};
      },async data=>{
        notice.textContent=(data.plano_alterado?'Plano de pagamento atualizado.':'Contrato recalculado.')+' '+data.parcelas_alteradas+' parcela(s) com valores atualizados · '+data.parcelas_preservadas+' com valores preservados.'+(data.competencias_alteradas?' '+data.competencias_alteradas+' competência(s) atualizada(s).':'');
        await load();
      });
    }catch(e){if(m.dialog.isConnected)m.status.textContent=e.message;}
  }
  async function getContract(id,title,done){const m=modal(title);m.status.textContent='Carregando contrato...';try{const c=await api('contratos/'+id);if(!m.dialog.isConnected)return;m.status.textContent='';await done(c,m);}catch(e){m.status.textContent=e.message;}return m;}
  async function printContract(current,m,button){
    const popup=window.open('','_blank');if(popup){popup.opener=null;popup.document.title='Preparando contrato';popup.document.body.textContent='Preparando o PDF do contrato...';}
    button.disabled=true;m.status.textContent='Preparando impressão...';
    try{
      const result=await api('contratos/'+current.idcontrato+'/pdf');if(result.mime!=='application/pdf'||typeof result.conteudo!=='string')throw Error('O servidor não retornou um PDF válido.');
      const raw=atob(result.conteudo);if(!raw.startsWith('%PDF-'))throw Error('O servidor não retornou um PDF válido.');
      const bytes=Uint8Array.from(raw,c=>c.charCodeAt(0)),url=URL.createObjectURL(new Blob([bytes],{type:'application/pdf'}));
      if(popup&&!popup.closed)popup.location.replace(url);
      const open=h('a','Abrir PDF para imprimir','erp-link-button');open.href=url;open.target='_blank';open.rel='noopener noreferrer';open.download=String(result.nome||'contrato.pdf').replace(/[\\/]/g,'-');
      m.status.replaceChildren(document.createTextNode('PDF pronto. Use a opção de impressão do leitor de PDF. '),open);setTimeout(()=>URL.revokeObjectURL(url),300000);
    }catch(error){if(popup&&!popup.closed)popup.close();m.status.textContent=error.message;}finally{button.disabled=false;}
  }
  function openContract(id){return getContract(id,'Contrato do aluno',(c,m)=>{
    m.content.append(h('h3',c.numero),summary(c),h('p','Versão do termo: '+c.versao_termo+(c.modelo_nome_snapshot?' · Modelo: '+c.modelo_nome_snapshot:'')),contractText(c.termo_snapshot,c.formato_texto));
    if(c.bolsas_aluno?.length&&window.EDERPScholarships)m.content.append(window.EDERPScholarships.contractStudentGrants(c.bolsas_aluno));
    const signature=h('section',undefined,'erp-contract-signature'),label=h('label',undefined,'ederp-check'),check=h('input');check.type='checkbox';check.name='assinado';check.checked=!!c.assinado_em;check.disabled=!!c.assinado_em;label.append(check,h('span','Contrato assinado'));signature.append(label);
    if(c.assinado_em){signature.classList.add('signed');signature.append(h('p','Texto bloqueado permanentemente. Registro por '+(c.assinado_por_nome||'usuário autorizado')+' em '+new Date(c.assinado_em.replace(' ','T')+'Z').toLocaleString('pt-BR')+'.'));}
    else if(c.status==='ativo'){
      signature.append(h('p','Confirme que este texto já foi assinado. Após registrar, a marcação e o texto serão permanentes. Bolsas, descontos e operações financeiras continuam disponíveis.'));
      const requestKey=uuid(),record=button('Registrar contrato assinado',async()=>{if(!check.checked||m.dialog.dataset.busy==='1')return;m.dialog.dataset.busy='1';record.disabled=check.disabled=m.close.disabled=true;m.status.textContent='Registrando assinatura...';try{await api('contratos/'+id+'/assinar',{versao:c.versao,confirmado:true},requestKey);m.dialog.close();notice.textContent='Contrato marcado como assinado. O texto está bloqueado permanentemente.';await load();await openContract(id);}catch(error){m.status.textContent=error.message;}finally{m.dialog.dataset.busy='0';record.disabled=!check.checked;check.disabled=m.close.disabled=false;}});record.disabled=true;check.onchange=()=>{record.disabled=!check.checked;};signature.append(record);
    }else{check.disabled=true;signature.append(h('p','A assinatura é registrada em contratos ativos.'));}
    const print=button('Imprimir contrato',()=>printContract(c,m,print));m.content.append(signature,print);signaturePanel(c,m);
  });}

  async function signatureSettings(){
    const m=modal('Configurar assinatura eletrônica');
    try{
      const config=await api('assinatura-eletronica/configuracao');
      if(!m.dialog.isConnected)return;
      const form=h('form',undefined,'ederp-form erp-contract-form');
      const base=field('base','URL HTTPS da API de assinatura',config.base,'url'),user=field('usuario','Login do gestor de contratos',config.usuario);
      base.input.placeholder='https://assinaturas.exemplo.com.br/wp-json/edu-sign/v1';
      const password=field('senha','Senha de aplicativo','','password',!config.tem_senha),secret=field('segredo','Segredo compartilhado do retorno','','password',false);
      password.input.autocomplete=secret.input.autocomplete='new-password';secret.input.minLength=32;
      if(config.tem_senha)password.input.placeholder='Preenchida. Deixe em branco para manter.';
      if(config.tem_segredo)secret.input.placeholder='Preenchido. Deixe em branco para manter.';
      const callback=field('retorno','URL de retorno do ERP',config.retorno,'text',false);callback.input.readOnly=true;
      form.append(base.node,user.node,password.node,secret.node,callback.node,h('p','No site de assinaturas, cadastre esta URL e o mesmo segredo em Contratos educacionais → Configurações → Retorno ao ERP Educacional.'));
      m.content.append(form);bind(m,form,'assinatura-eletronica/configuracao',()=>({base:base.input.value,usuario:user.input.value,senha:password.input.value,segredo:secret.input.value}),async()=>{notice.textContent='Integração de assinatura salva para esta coligada.';});
    }catch(error){m.status.textContent=error.message;}
  }
  async function signaturePanel(c,m){
    const section=h('section',undefined,'erp-contract-signature'),title=h('h3','Assinatura eletrônica'),body=h('div'),feedbackNode=feedback();
    section.append(title,body,feedbackNode);m.content.append(section);
    const path='contratos/'+c.idcontrato+'/assinatura';
    let busy=false,sendKey=uuid();
    async function run(fn){
      if(busy)return;busy=true;const buttons=[...body.querySelectorAll('button')];buttons.forEach(b=>b.disabled=true);
      feedbackNode.textContent='Consultando o envio...';
      try{await fn();feedbackNode.textContent='';}
      catch(error){feedbackNode.textContent=error.message;}
      finally{busy=false;buttons.forEach(b=>b.disabled=false);}
    }
    async function refresh(data){
      if(data.items.some(r=>r.situacao==='signed'&&Number(r.vinculado))&&!c.assinado_em){m.dialog.close();await load();await openContract(c.idcontrato);return;}
      paint(data);
    }
    function paint(data){
      body.replaceChildren();
      const rows=data.items||[],latest=rows[0];
      if(!data.configurado&&!rows.length){body.append(h('p','A integração de assinatura ainda precisa ser configurada para esta coligada.'));return;}
      const open=latest&&pendingSignature.has(latest.situacao);
      if(data.configurado&&!c.assinado_em&&c.status==='ativo'&&(!open||latest.situacao==='preparado')){
        if(!open)sendKey=uuid();
        body.append(h('p','O responsável financeiro '+c.responsavel+(c.responsavel_email?' ('+c.responsavel_email+')':'')+' receberá o convite por e-mail.'),
          button(open?'Retomar envio':'Enviar PDF para assinatura',()=>run(async()=>refresh(await api(path+'/enviar',{versao:c.versao},sendKey)))));
      }
      for(const row of rows){
        const item=h('article',undefined,'erp-scholarship-card'),ref=path+'/'+row.idenvio;
        item.append(h('strong','Revisão '+row.revisao+' · '+(signatureLabels[row.situacao]||row.situacao)),h('p','Referência '+row.referencia));
        if(row.uuid)item.append(h('p','Protocolo '+row.uuid));
        if(pendingSignature.has(row.situacao)){
          item.append(button('Consultar situação',()=>run(async()=>refresh(await api(ref+'/consultar',{})))));
          item.append(button('Cancelar solicitação',()=>{if(window.confirm('Cancelar o envio desta revisão para assinatura?'))run(async()=>refresh(await api(ref+'/cancelar',{})));},'erp-secondary'));
        }
        if(row.situacao==='signed'){
          item.append(h('p','Assinatura em '+new Date(row.assinado_em.replace(' ','T')+'Z').toLocaleString('pt-BR')+'.'));
          if(!Number(row.vinculado))item.append(h('p','Esta assinatura corresponde à revisão enviada. Confira o PDF antes de emitir a redação atual.'));
          item.append(button('Baixar PDF assinado',()=>run(async()=>{
            const result=await api(ref+'/pdf'),raw=atob(result.conteudo);
            if(result.mime!=='application/pdf'||!raw.startsWith('%PDF-'))throw Error('Arquivo de assinatura indisponível.');
            const url=URL.createObjectURL(new Blob([Uint8Array.from(raw,ch=>ch.charCodeAt(0))],{type:'application/pdf'}));
            const a=document.createElement('a');a.href=url;a.download=result.nome;document.body.append(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),60000);
          })));
        }
        body.append(item);
      }
    }
    try{const data=await api(path);if(m.dialog.isConnected)paint(data);}
    catch(error){feedbackNode.textContent=error.message;}
  }

  function bind(m,form,path,payload,onDone){const save=h('button','Salvar'),status=feedback();save.type='submit';form.append(save,status);let signature='',key='';form.onsubmit=async e=>{e.preventDefault();if(m.dialog.dataset.busy==='1'||!form.reportValidity())return;try{const body=payload(),mark=JSON.stringify(body);if(mark!==signature){signature=mark;key=uuid();}m.dialog.dataset.busy='1';save.disabled=m.close.disabled=true;status.textContent='Salvando...';const result=await api(path,body,key);m.dialog.close();await onDone(result);}catch(e){status.textContent=e.message;status.setAttribute('role','alert');}finally{m.dialog.dataset.busy='0';save.disabled=m.close.disabled=false;}};}
  function editContract(id){return getContract(id,'Editar contrato',async(c,m)=>{const form=h('form',undefined,'ederp-form erp-contract-form'),number=field('numero','Número do contrato',c.numero),contractDate=field('data_contrato','Data do contrato',c.data_contrato,'date'),term=field('termo','Texto do contrato',c.termo_snapshot,'textarea'),version=field('versao_termo','Versão do termo',c.versao_termo),reason=field('motivo','Motivo da alteração','','textarea');number.input.maxLength=100;term.input.maxLength=40000;version.input.maxLength=40;reason.input.maxLength=2000;m.content.append(summary(c),form);form.append(number.node,contractDate.node);
    const financial=[];if(!Number(c.parcelas_geradas)&&!c.total_parcelas){for(const [name,label,type]of [...(c.mensalidade_base_total!=null?[['mensalidade_base_total','Anuidade sem o componente de pontualidade (R$)','text'],['desconto_base_total','Desconto manual total (R$)','text']]:[['valor_original_total','Valor original total (R$)','text'],['desconto_incondicional_total','Bolsa total (R$)','text']]),['quantidade_parcelas','Quantidade de parcelas','number'],['primeiro_vencimento','Primeiro vencimento','date']]){const f=field(name,label,c[name],type);form.append(f.node);financial.push(f);}}else form.append(h('p','As parcelas já foram geradas. Use o Financeiro para ajustar os lançamentos.'));
    const format=choice('formato_texto','Formato do texto',[['auto','Identificar automaticamente'],['html','HTML'],['texto','Texto simples']]);format.input.value=c.formato_texto==='html'?'html':'auto';const select=choice('idmodelo','Texto do contrato',[['','Manter ou editar o texto atual']]);if(c.assinado_em)form.append(h('p','Texto assinado: redação, versão e modelo estão bloqueados permanentemente. Os demais dados seguem suas permissões.'));else form.append(select.node,format.node,term.node,version.node,h('p',htmlHint),h('p','Ao alterar o texto, informe uma nova versão. Aplicar um modelo substitui o texto deste contrato.'));form.append(reason.node);
    const data=c.assinado_em?{items:[]}:await api('modelos-contrato');if(!m.dialog.isConnected)return;data.items.forEach(row=>select.input.append(new Option(row.nome+' · '+row.versao_texto,row.idmodelo)));select.input.onchange=()=>{const chosen=!!select.input.value;format.node.hidden=term.node.hidden=version.node.hidden=chosen;term.input.required=version.input.required=!chosen;};
    bind(m,form,'contratos/'+id,()=>{const body={versao:c.versao,numero:number.input.value,data_contrato:contractDate.input.value,motivo:reason.input.value};if(!c.assinado_em&&select.input.value){const model=data.items.find(row=>String(row.idmodelo)===select.input.value);body.idmodelo=model.idmodelo;body.modelo_versao=model.versao;}else if(!c.assinado_em){body.termo=term.input.value;body.versao_termo=version.input.value;if(format.input.value!=='auto')body.formato_texto=format.input.value;}for(const f of financial)body[f.input.name]=f.input.value.includes(',')?f.input.value.replaceAll('.','').replace(',','.'):f.input.value;return body;},async()=>{notice.textContent='Contrato atualizado.';await load();});
  });}
  function removeContract(id){return getContract(id,'Excluir contrato',(c,m)=>{m.content.append(h('h3',c.numero),summary(c));if(c.lancamentos_ativos){m.content.append(h('p','Há '+c.lancamentos_ativos+' lançamentos vinculados. Exclua esses lançamentos na Gestão financeira antes de excluir o contrato.'),link('Abrir Gestão financeira','financeiro'));return;}
    const form=h('form',undefined,'ederp-form'),reason=field('motivo','Motivo da exclusão','','textarea'),check=h('label',undefined,'ederp-check'),confirm=h('input');confirm.type='checkbox';confirm.name='confirmado';confirm.required=true;check.append(confirm,h('span','Confirmo a exclusão do contrato '+c.numero+'.'));reason.input.maxLength=2000;form.append(h('p','O contrato será retirado das consultas. Os textos, parcelas, pagamentos e a auditoria serão preservados. Esta ação não cancela faturas no Educbank.'),reason.node,check);m.content.append(form);bind(m,form,'contratos/'+id+'/excluir',()=>({versao:c.versao,motivo:reason.input.value,confirmado:confirm.checked}),async()=>{notice.textContent='Contrato excluído.';await load();});form.querySelector('button').textContent='Excluir contrato';form.querySelector('button').classList.add('erp-contract-danger');
  });}
  async function loadModels(){
    modelPanel.replaceChildren(h('p','Carregando modelos...'));
    try{
      const d=await api('modelos-contrato?todos=1');
      models=d.items;tokens=d.shortcodes;externalTokens=d.shortcodes_externos||[];
      const tools=h('div',undefined,'erp-contract-toolbar erp-contract-model-tools'),grid=h('div',undefined,'erp-contract-model-grid');
      tools.append(button('Novo modelo',()=>editModel()));
      if(EDERP.caps?.admin)tools.append(button('Shortcodes externos',editExternalTokens,'erp-secondary'));
      modelPanel.replaceChildren(h('p','Os modelos preenchem os dados de cada aluno. Alterações em modelos atualizam os textos dos contratos ainda não assinados. Textos assinados ficam preservados permanentemente.'),tools,grid);
      if(!models.length)grid.append(h('p','Nenhum modelo cadastrado. Cadastre um texto e marque-o como padrão para novas emissões.'));
      for(const model of models){
        const card=h('article',undefined,'ederp-card'),actions=h('div',undefined,'erp-contract-toolbar erp-contract-model-actions');
        actions.append(button('Editar modelo',()=>editModel(model),'erp-secondary'),button('Excluir modelo',()=>removeModel(model),'erp-contract-danger'));
        card.append(h('h2',model.nome),h('p','Versão '+model.versao_texto+' · '+(Number(model.ativo)?'Ativo':'Inativo')+(Number(model.padrao)?' · Padrão':'')),h('p',model.texto.slice(0,160)+(model.texto.length>160?'…':'')),actions);
        grid.append(card);
      }
    }catch(e){modelPanel.replaceChildren(h('p',e.message),button('Tentar novamente',loadModels));}
  }
  function editExternalTokens(){
    const m=modal('Shortcodes externos da coligada'),form=h('form',undefined,'ederp-form erp-contract-form');
    const previous=[...externalTokens];
    const entry=field('expressoes','Shortcodes para usar nos contratos',previous.join('\n'),'textarea',false);
    entry.input.placeholder='[unidade_escolar]\n[unidade_escolar campus="centro"]';
    form.append(h('p','Use um shortcode informativo por linha, exatamente como ele será inserido no contrato, incluindo os parâmetros fixos. O plugin ou tema que fornece o texto deve estar ativo.'),entry.node);
    m.content.append(form);
    bind(m,form,'modelos-contrato/shortcodes-externos',()=>({
      expressoes:entry.input.value.split(/\r?\n/).map(line=>line.trim()).filter(Boolean),
      anteriores:previous
    }),async()=>{notice.textContent='Shortcodes externos salvos para esta coligada.';await loadModels();});
  }
  function insertText(textarea,value){
    textarea.setRangeText(value,textarea.selectionStart,textarea.selectionEnd,'end');
    textarea.focus();
    textarea.dispatchEvent(new Event('input',{bubbles:true}));
  }
  function tuitionClauseButton(textarea){
    const action=button('Inserir cláusula de anuidade',()=>insertText(textarea,tuitionClause));
    const refresh=()=>{action.hidden=textarea.value.includes(tuitionClause);};
    textarea.addEventListener('input',refresh);
    refresh();
    return action;
  }
  function insertTokens(textarea){
    const details=h('details',undefined,'erp-contract-tokens'),items=h('div');
    details.append(h('summary','Shortcodes disponíveis — clique para inserir'),items);
    for(const [name,label] of Object.entries(tokens)){
      const token='['+name+']';
      const action=button(token,()=>insertText(textarea,token));
      action.title=label;
      items.append(action);
    }
    for(const token of externalTokens)items.append(button(token,()=>insertText(textarea,token)));
    return details;
  }
  function editModel(model){const m=modal(model?'Editar modelo de contrato':'Novo modelo de contrato'),form=h('form',undefined,'ederp-form erp-contract-form'),name=field('nome','Nome do modelo',model?.nome||''),version=field('versao_texto','Versão do texto',model?.versao_texto||'1'),text=field('texto','Texto com shortcodes',model?.texto||defaultModelText,'textarea'),enabled=choice('ativo','Situação',[['1','Ativo'],['0','Inativo']]),standard=choice('padrao','Usar como padrão',[['0','Não'],['1','Sim']]);name.input.maxLength=191;version.input.maxLength=40;text.input.maxLength=40000;enabled.input.value=String(model?.ativo??1);standard.input.value=String(model?.padrao??0);form.append(name.node,version.node,text.node,tuitionClauseButton(text.input),insertTokens(text.input),h('p',htmlHint),enabled.node,standard.node);
    const reason=model?field('motivo','Motivo da alteração','','textarea'):null;if(reason){reason.input.maxLength=2000;form.append(reason.node);form.append(h('p','Ao alterar o texto, informe uma nova versão.'));}
    const format=choice('formato_texto','Formato do texto',[['auto','Identificar automaticamente'],['html','HTML'],['texto','Texto simples']]);format.input.value=model?.formato_texto==='html'?'html':'auto';text.node.before(format.node);m.content.append(form);const preview=button('Prévia com dados de um contrato',async()=>{preview.disabled=true;try{const d=await api('contratos?codperiodo=todos');if(!m.dialog.isConnected)return;let panel=m.content.querySelector('[data-contract-preview]');if(panel)panel.remove();panel=h('section',undefined,'ederp-card');panel.dataset.contractPreview='1';if(!d.items.length){panel.append(h('p','Cadastre um contrato de aluno para visualizar os dados preenchidos.'));m.content.append(panel);return;}const selection=choice('contrato_referencia','Contrato de referência',d.items.map(c=>[c.idcontrato,c.numero+' · '+c.aluno])),status=feedback(),out=h('div'),show=button('Gerar prévia',async()=>{show.disabled=true;try{const r=await api('modelos-contrato/previa',{idcontrato:selection.input.value,texto:text.input.value,formato_texto:format.input.value});out.replaceChildren(contractText(r.texto,r.formato_texto));status.textContent='Prévia gerada com os dados atuais do contrato. Nenhum contrato foi alterado.';}catch(e){status.textContent=e.message;}finally{show.disabled=false;}});panel.append(selection.node,show,status,out);m.content.append(panel);}catch(e){m.status.textContent=e.message;}finally{preview.disabled=false;}});form.append(preview);
    bind(m,form,'modelos-contrato'+(model?'/'+model.idmodelo:''),()=>({nome:name.input.value,versao_texto:version.input.value,texto:text.input.value,...(format.input.value!=='auto'?{formato_texto:format.input.value}:{}),ativo:enabled.input.value,padrao:standard.input.value,...(model?{versao:model.versao,motivo:reason.input.value}:{})}),async(result)=>{notice.textContent='Modelo salvo. '+(result.contratos_atualizados||0)+' textos de contratos não assinados atualizados. Textos assinados foram preservados.';await loadModels();});
  }
  function removeModel(model){const m=modal('Excluir modelo '+model.nome),form=h('form',undefined,'ederp-form'),reason=field('motivo','Motivo da exclusão','','textarea');reason.input.maxLength=2000;form.append(h('p','O modelo deixará de aparecer nas novas emissões. Os contratos já gerados serão preservados.'+(Number(model.padrao)?' Este é o modelo padrão; defina outro após a exclusão.':'')),reason.node);m.content.append(form);bind(m,form,'modelos-contrato/'+model.idmodelo+'/excluir',()=>({versao:model.versao,motivo:reason.input.value}),async()=>{notice.textContent='Modelo excluído.';await loadModels();});form.querySelector('button').textContent='Excluir modelo';}
  document.addEventListener('ederp:refresh',()=>active==='models'?loadModels():load());switchTab(params.get('aba')==='modelos'?'models':'contracts');
})();
