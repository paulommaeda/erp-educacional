(() => {
  'use strict';
  const root=document.querySelector('[data-erp-financial-dashboard]');if(!root)return;
  root.classList.add('erp-financial-dashboard');
  const el=(tag,text,cls)=>{const node=document.createElement(tag);if(text!==undefined)node.textContent=text;if(cls)node.className=cls;return node;};
  const money=value=>new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'}).format(Number(value||0));
  const date=value=>String(value).split('-').reverse().join('/');
  const month=value=>String(value).slice(0,7).split('-').reverse().join('/');
  const percent=value=>Number(value||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2})+'%';
  const button=(text,fn)=>{const b=el('button',text,'erp-secondary');b.type='button';b.addEventListener('click',fn);return b;};
  async function api(path,query){let r,data;try{r=await fetch(EDERP.root+path+'?'+new URLSearchParams(query),{credentials:'same-origin',cache:'no-store',headers:{'X-WP-Nonce':EDERP.nonce}});data=await r.json();}catch{throw Error('Não foi possível obter a resposta. Confira sua conexão e tente novamente.');}if(!r.ok)throw Error(data.message||'Não foi possível consultar a Gestão financeira.');return data;}
  const form=el('form',undefined,'erp-dashboard-filters'),summaryPanel=el('section'),result=el('section'),apply=el('button','Aplicar filtros'),heading=el('h1','Gestão financeira');
  result.setAttribute('aria-live','polite');apply.type='submit';
  const field=(name,label,type)=>{const node=el('label',label),input=el('input');input.name=name;input.type=type;node.append(input);return {node,input};};
  const from=field('competencia_inicio','Competência inicial','month'),to=field('competencia_fim','Competência final','month');
  const consultationPeriod=()=>String(new URLSearchParams(location.search).get('erp_periodo')||EDERP.period||EDERP.currentPeriod||'todos');let reportSerial=0;
  let classroom,student;
  const query=()=>({codperiodo:consultationPeriod(),competencia_inicio:from.input.value,competencia_fim:to.input.value,idturma:classroom?.select.value||'',idaluno:student?.select.value||''});
  function lookup(type,label){
    const box=el('fieldset',undefined,'erp-dashboard-lookup'),legend=el('legend',label),search=field(type+'_busca','Buscar por '+(type==='turmas'?'código ou nome':'nome ou RA'),'search'),selectLabel=el('label','Selecionar '+(type==='turmas'?'turma':'aluno')),select=el('select');select.name=type==='turmas'?'idturma':'idaluno';
    const empty=()=>new Option(type==='turmas'?'Todas as turmas':'Todos os alunos','');select.append(empty());selectLabel.append(select);
    const tools=el('div',undefined,'erp-dashboard-lookup-tools'),notice=el('small'),searchButton=button('Buscar',()=>load(1)),previous=button('Anterior',()=>load(page-1)),next=button('Próxima',()=>load(page+1)),clear=button('Limpar seleção',()=>{select.value='';select.dispatchEvent(new Event('change'));});let page=1,serial=0;
    previous.disabled=next.disabled=true;tools.append(searchButton,previous,next,clear);box.append(legend,search.node,selectLabel,tools,notice);
    search.input.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();load(1);}});
    async function load(target=1){const turn=++serial,selected=select.value,selectedText=select.selectedOptions[0]?.textContent;previous.disabled=next.disabled=true;notice.textContent='Buscando opções...';
      try{const data=await api('gestao-financeira/filtros',{...query(),tipo:type,search:search.input.value,page:target});if(turn!==serial)return;page=data.page;select.replaceChildren(empty());
        if(selected&&!data.items.some(row=>String(row.id)===selected))select.append(new Option(selectedText,selected));
        data.items.forEach(row=>select.append(new Option(row.label,String(row.id))));select.value=selected;
        previous.disabled=page<=1;next.disabled=!data.more;notice.textContent=data.items.length?'Página '+page+(data.more?' · Há mais opções':''):'Nenhum resultado para a busca.';
      }catch(error){if(turn===serial)notice.textContent=error.message;}
    }
    return {box,select,search:search.input,load};
  }
  classroom=lookup('turmas','Turma');student=lookup('alunos','Aluno');
  classroom.select.addEventListener('change',()=>{student.select.value='';student.load(1);});
  const tools=el('div',undefined,'erp-dashboard-filter-actions'),reset=button('Limpar filtros',()=>{form.reset();classroom.select.value=student.select.value='';classroom.load(1);student.load(1);load();});tools.append(apply,reset);
  form.append(from.node,to.node,classroom.box,student.box,tools);
  root.replaceChildren(heading,el('p','Acompanhe os recebíveis da coligada e do período selecionados. Os recebimentos são atribuídos à competência da parcela.'),summaryPanel,form,result);
  function card(label,key,data,tone,note){const node=el('article',undefined,'erp-dashboard-card '+tone);node.dataset.indicator=key;node.append(el('h2',label),el('strong',money(data[key]),'erp-dashboard-value'),el('p',note));return node;}
  function render(data,labels){
    const totals=data.totais,cards=el('div',undefined,'erp-dashboard-cards'),caption=el('p','Saldos em '+date(data.data_referencia)+' · '+labels.join(' · '),'erp-dashboard-caption');
    cards.append(
      card('Previsto','previsto',totals,'forecast','Com pontualidade · Bruto: '+money(totals.previsto_bruto)),
      card('Realizado','realizado',totals,'received','Principal recebido · Total com encargos: '+money(totals.recebido_total)),
      card('Inadimplência bruta','inadimplencia_bruta',totals,'overdue',percent(totals.taxa_inadimplencia_bruta)+' da base vencida · Sem pontualidade'),
      card('Inadimplência líquida','inadimplencia_liquida',totals,'net',percent(totals.taxa_inadimplencia_liquida)+' da base vencida · Com pontualidade')
    );
    const balance=el('p','A receber: '+money(totals.a_receber_bruto)+' sem pontualidade · '+money(totals.a_receber_liquido)+' com pontualidade · '+totals.parcelas_vencidas+' parcelas vencidas de '+totals.parcelas+' consultadas.','erp-dashboard-balance');
    summaryPanel.replaceChildren(caption,cards,balance);result.replaceChildren();
    if(!data.competencias.length)result.append(el('p','Nenhum lançamento encontrado para estes filtros.','erp-feedback'));
    else{
      const title=el('h2','Por competência'),wrap=el('div',undefined,'erp-dashboard-table-wrap'),table=el('table',undefined,'erp-dashboard-table'),head=el('thead'),tr=el('tr'),body=el('tbody');
      const columns=[['competencia','Competência',month],['previsto_bruto','Previsto bruto',money],['previsto','Previsto com pontualidade',money],['realizado','Realizado',money],['inadimplencia_bruta','Inadimplência bruta',money],['inadimplencia_liquida','Inadimplência líquida',money]];
      columns.forEach(([,label])=>{const th=el('th',label);th.scope='col';tr.append(th);});head.append(tr);table.append(head,body);
      data.competencias.forEach(row=>{const line=el('tr');columns.forEach(([key,label,format])=>{const cell=el('td',format(row[key]));cell.dataset.label=label;
        if(key==='realizado'){const progress=el('progress');progress.max=100;progress.value=Number(row.previsto)>0?Math.min(100,Number(row.realizado)/Number(row.previsto)*100):0;progress.setAttribute('aria-label','Realizado em relação ao previsto em '+month(row.competencia));cell.append(progress);}
        if(key.startsWith('inadimplencia_'))cell.append(el('small',percent(row['taxa_'+key])));line.append(cell);});body.append(line);});wrap.append(table);result.append(title,wrap);
    }
    const details=el('details',undefined,'erp-dashboard-method'),summary=el('summary','Como os indicadores são calculados'),list=el('ul');
    [
      'Bolsas e descontos incondicionais já são retirados de todas as bases. O previsto considera a pontualidade; valores já recebidos de principal são preservados.',
      'Realizado soma o principal das baixas válidas. O total recebido também inclui juros e multas; baixas estornadas ficam fora dos dois valores.',
      'Inadimplência bruta é o principal vencido ainda em aberto, após pagamentos e descontos já concedidos, sem deduzir a pontualidade restante.',
      'Inadimplência líquida é esse saldo após simular a pontualidade restante. Essa projeção para análise não concede desconto aos títulos vencidos.',
      'As taxas usam as respectivas bases das parcelas com vencimento anterior a hoje. Parcelas que vencem hoje entram no previsto e a receber.',
      'A consulta usa valores atuais, a competência da parcela e a turma atual da matrícula. Saldos cancelados ou excluídos ficam fora da previsão e do atraso; recebimentos válidos preservados em títulos cancelados continuam no realizado.'
    ].forEach(text=>list.append(el('li',text)));details.append(summary,list);result.append(details);
  }
  async function load(){
    if(from.input.value&&to.input.value&&from.input.value>to.input.value){result.replaceChildren(el('p','A competência inicial deve ser anterior à final.','erp-feedback error'));return;}
    const turn=++reportSerial,q=query(),labels=[q.competencia_inicio||q.competencia_fim?'Competências: '+(q.competencia_inicio?month(q.competencia_inicio):'início')+' a '+(q.competencia_fim?month(q.competencia_fim):'fim'):'Todas as competências',classroom.select.selectedOptions[0].textContent,student.select.selectedOptions[0].textContent];
    result.setAttribute('aria-busy','true');summaryPanel.replaceChildren();result.replaceChildren(el('p','Calculando indicadores...','erp-feedback'));apply.disabled=true;
    try{const data=await api('gestao-financeira',q);if(turn===reportSerial)render(data,labels);}
    catch(error){if(turn===reportSerial)result.replaceChildren(el('p',error.message,'erp-feedback error'),button('Tentar novamente',load));}
    finally{if(turn===reportSerial){result.removeAttribute('aria-busy');apply.disabled=false;}}
  }
  function refreshPeriod(){const period=consultationPeriod(),choice=document.querySelector('.erp-period-bar select');EDERP.period=period;window.EDERPConsultationPeriod?.(period);if(choice)choice.value=period;from.input.value=to.input.value='';classroom.select.value=student.select.value='';classroom.search.value=student.search.value='';classroom.load(1);student.load(1);load();}
  window.addEventListener('ederp:period-changed',refreshPeriod);window.addEventListener('popstate',refreshPeriod);
  form.addEventListener('submit',event=>{event.preventDefault();load();});classroom.load(1);student.load(1);load();
})();
