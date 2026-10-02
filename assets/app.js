(() => {
  'use strict';
  const el = (tag, text, cls) => { const n=document.createElement(tag); if(text!==undefined)n.textContent=text; if(cls)n.className=cls; return n; };
  const uuid = () => globalThis.crypto?.randomUUID?.() || Array.from(globalThis.crypto.getRandomValues(new Uint8Array(24)),n=>n.toString(16).padStart(2,'0')).join('');
  async function api(path, data, key) {
    const r=await fetch(EDERP.root+path,{method:data?'POST':'GET',credentials:'same-origin',cache:'no-store',headers:{'X-WP-Nonce':EDERP.nonce,...(data?{'Content-Type':'application/json','Idempotency-Key':key}: {})},...(data?{body:JSON.stringify(data)}:{})});
    let j; try { j=await r.json(); } catch { throw Error('O servidor retornou uma resposta inválida. Confira a conexão antes de tentar novamente.'); }
    if(!r.ok)throw Error(j.message||'Operação não concluída.'); return j;
  }
  const periodQuery=()=>'?codperiodo='+encodeURIComponent(EDERP.period||'todos');
  window.EDERPPeriodQuery=periodQuery;
  const scopeHost=document.querySelector('.journey-content')||document.querySelector('.erp-workflow')?.parentElement;
  const scopeView=document.querySelector('[data-journey-screen]')?.dataset.journeyScreen||new URLSearchParams(location.search).get('erp_tela')||document.querySelector('[data-erp-screen]')?.dataset.erpScreen||document.querySelector('[data-journey-view]')?.dataset.journeyView;
  if(scopeHost&&EDERP.caps?.periods&&['inicio','academico','financeiro','alunos'].includes(scopeView)){
    const bar=el('div',undefined,'erp-period-bar'),label=el('label','Período da consulta'),choice=el('select');label.append(choice);bar.append(label);if(document.querySelector('.journey-content'))scopeHost.prepend(bar);else document.querySelector('.erp-workflow').before(bar);
    api('configuracoes').then(d=>{choice.append(new Option('Todos os períodos','todos'));d.periodos.forEach(p=>choice.append(new Option(p.codigo+' — '+p.descricao,p.codperiodo)));choice.value=String(EDERP.period||d.codperiodo||'todos');if(!d.codperiodo)bar.append(el('small','Período atual ainda não configurado.'));choice.onchange=()=>{const u=new URL(location.href);u.searchParams.set('erp_periodo',choice.value);location.href=u.href;};}).catch(e=>bar.append(el('small',e.message)));
  }
  const labels={idaluno:'ID aluno',codpessoa:'Pessoa',ra:'RA',nome:'Nome',cpf:'CPF',idmatricula:'Matrícula',idturma:'Turma',codperiodo:'Período',idcurso:'Curso',idturno:'Turno',codigo:'Código',descricao:'Descrição',status:'Situação',vencimento:'Vencimento',valor_baixa:'Pago',saldo_aberto:'Saldo',idlancamento:'Lançamento',idcontrato:'Contrato'};
  function table(rows, onSelect) {
    const wrap=el('div',undefined,'ederp-table-wrap');
    if(!rows.length){wrap.append(el('p','Nenhum registro encontrado.'));return wrap;}
    const keys=Object.keys(rows[0]).filter(k=>!['criado_em','atualizado_em','versao','termo_snapshot'].includes(k)&&typeof rows[0][k]!=='object');
    const t=el('table',undefined,'journey-cards'), head=el('tr'); keys.forEach(k=>head.append(el('th',labels[k]||k.replaceAll('_',' ')))); if(onSelect)head.append(el('th','Ficha'));
    const thead=el('thead');thead.append(head);t.append(thead);const body=el('tbody');
    rows.forEach(row=>{const tr=el('tr');keys.forEach(k=>{const td=el('td',row[k]??'');td.dataset.label=labels[k]||k.replaceAll('_',' ');tr.append(td);});if(onSelect){const cell=el('td'),b=el('button','Abrir');b.type='button';b.onclick=()=>onSelect(row);cell.append(b);tr.append(cell);}body.append(tr);});
    t.append(body);wrap.append(t);return wrap;
  }
  function field(name,label,type='text',required=true){const l=el('label',label),i=el(type==='textarea'?'textarea':'input');i.name=name;if(type!=='textarea')i.type=type;i.required=required;l.append(i);return l;}
  function form(title,route,fields,preset={}){
    const card=el('section',undefined,'ederp-card');card.append(el('h3',title));const f=el('form',undefined,'ederp-form');f.dataset.ederpForm=route;
    fields.forEach(([n,l,t])=>f.append(field(n,l,t||'text')));
    Object.entries(preset).forEach(([name,value])=>{const i=el('input');i.type='hidden';i.name=name;i.value=value;f.append(i);});
    const b=el('button','Confirmar');b.type='submit';f.append(b);const status=el('p',undefined,'ederp-status');status.setAttribute('role','status');f.append(status);card.append(f);return card;
  }
  document.addEventListener('submit',async e=>{
    const f=e.target;if(!f.matches('[data-ederp-form]'))return;e.preventDefault();
    const data={};for(const [k,v] of new FormData(f)){if(v!=='')data[k]=v;}
    let route=f.dataset.ederpForm;
    if(route.includes('{id}')){route=route.replace('{id}',encodeURIComponent(data.id));delete data.id;}
    for(const k of ['valor_pago','valor_delta','valor_total','valor_original_total','desconto_incondicional_total'])if(typeof data[k]==='string'&&data[k].includes(','))data[k]=data[k].replaceAll('.','').replace(',','.');
    if(data.turmas)data.turmas=data.turmas.split(',').map(s=>s.trim()).filter(Boolean);
    if(Object.hasOwn(data,'aceite'))data.aceite=true;
    const fingerprint=route+'|'+JSON.stringify(data);
    if(f.dataset.fingerprint!==fingerprint){f.dataset.key=uuid();f.dataset.fingerprint=fingerprint;}
    const status=f.querySelector('.ederp-status'),button=f.querySelector('[type=submit]');button.disabled=true;status.textContent='Processando...';
    try{const result=await api(route,data,f.dataset.key);status.textContent='Operação concluída com sucesso.';f.dataset.completed='1';document.dispatchEvent(new Event('ederp:refresh'));}
    catch(err){status.textContent=err.message;}finally{button.disabled=false;}
  });
  document.querySelectorAll('[data-ederp-list]').forEach(container=>{
    let page=1,q='';const tools=el('form',undefined,'ederp-tools'),search=field('search','Pesquisar'),button=el('button','Buscar');button.type='submit';tools.append(search,button);
    const result=el('div'),nav=el('div',undefined,'ederp-tools'),prev=el('button','Anterior'),next=el('button','Próxima'),info=el('span');prev.type=next.type='button';nav.append(prev,info,next);container.append(tools,result,nav);
    async function load(){result.textContent='Carregando...';try{const d=await api(container.dataset.ederpList+'?page='+page+'&search='+encodeURIComponent(q));result.replaceChildren(table(d.items,container.dataset.students?studentSheet:null));info.textContent='Página '+page+' · '+d.total+' registros';prev.disabled=page===1;next.disabled=page*20>=d.total;}catch(e){result.textContent=e.message;}}
    tools.onsubmit=e=>{e.preventDefault();q=new FormData(tools).get('search');page=1;load();};prev.onclick=()=>{page--;load();};next.onclick=()=>{page++;load();};document.addEventListener('ederp:refresh',load);load();
  });
  async function studentSheet(row){
    const area=document.querySelector('#ederp-student');area.replaceChildren(el('h2','Ficha do aluno · RA '+row.ra));area.scrollIntoView({behavior:'smooth'});
    const hist=el('div');area.append(el('h3','Histórico de matrículas'),hist);try{hist.append(table(await api('alunos/'+row.idaluno+'/matriculas')));}catch(e){hist.textContent=e.message;}
    const guardians=el('div');area.append(el('h3','Responsáveis e vigências'),guardians);try{guardians.append(table(await api('alunos/'+row.idaluno+'/responsaveis')));}catch(e){guardians.textContent=e.message;}
    area.append(form('Vincular responsável','alunos/'+row.idaluno+'/responsaveis',[
      ['codpessoa_responsavel','Código da pessoa','number'],['parentesco','Parentesco: mae, pai, tutor, proprio_aluno ou outro'],
      ['responsavel_academico','Responsável acadêmico: 0 ou 1','number'],['responsavel_financeiro','Responsável financeiro: 0 ou 1','number'],['pode_rematricular','Pode rematricular: 0 ou 1','number']]));
    area.append(form('Matrícula inicial','matriculas',[['idturma','ID da turma','number'],['valor_original_total','Valor original total (ex.: 12000.00)'],['desconto_incondicional_total','Bolsa total (ex.: 0.00)'],['quantidade_parcelas','Quantidade de parcelas','number'],['primeiro_vencimento','Primeiro vencimento','date']],{idaluno:row.idaluno}));
  }
  document.querySelectorAll('[data-ederp-pending]').forEach(host=>{
    const f=el('form',undefined,'ederp-tools'),search=field('search','Buscar contrato por aluno ou RA','search',false),b=el('button','Buscar');b.type='submit';f.append(search,b);const out=el('div'),nav=el('div',undefined,'ederp-tools'),info=el('span'),prev=el('button','Anterior'),next=el('button','Próxima');prev.type=next.type='button';nav.append(prev,info,next);host.append(el('p','Rematrículas geram contratos sem cobranças. Confira o período da consulta para localizar os contratos do próximo ano.'),f,out,nav);let page=1;
    async function load(){out.replaceChildren(el('p','Carregando...'));try{const d=await api('financeiro/pendentes'+periodQuery()+'&page='+page+'&search='+encodeURIComponent(f.querySelector('input').value));out.replaceChildren();for(const c of d.items){const box=el('article',undefined,'ederp-card');box.append(el('h3',c.aluno+' · RA '+c.ra),el('p',c.periodo+' · '+c.turma+' · '+c.numero),el('p',c.plano+' · Anuidade R$ '+c.valor_original_total+' · Líquido R$ '+c.valor_liquido_total),el('p',c.quantidade_parcelas+' parcelas · Primeiro vencimento: '+c.primeiro_vencimento));const button=el('button','Gerar parcelas');button.type='button';const feedback=el('p');feedback.setAttribute('role','status');const key=uuid();button.onclick=()=>{const dialog=el('dialog',undefined,'journey-confirm'),confirm=el('button','Confirmar geração'),cancel=el('button','Cancelar'),status=el('p');dialog.append(el('h2','Gerar parcelas · '+c.aluno),el('p','Contrato '+c.numero+': '+c.quantidade_parcelas+' parcelas, total líquido R$ '+c.valor_liquido_total+'. Esta ação cria os lançamentos a receber.'),confirm,cancel,status);cancel.onclick=()=>dialog.close();dialog.onclose=()=>dialog.remove();confirm.onclick=async()=>{confirm.disabled=true;cancel.disabled=true;try{await api('contratos/'+c.idcontrato+'/parcelas',{confirmado:true},key);dialog.close();await load();}catch(e){status.textContent=e.message;confirm.disabled=false;cancel.disabled=false;}};document.body.append(dialog);dialog.showModal();};box.append(button,feedback);out.append(box);}if(!d.items.length)out.append(el('p','Nenhum contrato aguardando parcelas neste período.'));info.textContent=d.total+' contratos · Página '+page;prev.disabled=page===1;next.disabled=page*20>=d.total;}catch(e){out.textContent=e.message;}}
    f.onsubmit=e=>{e.preventDefault();page=1;load();};prev.onclick=()=>{page--;load();};next.onclick=()=>{page++;load();};load();
  });
  document.querySelectorAll('[data-ederp-finance-search]').forEach(c=>{
    const f=el('form',undefined,'ederp-tools'),q=field('search','Buscar aluno por nome ou RA','search',false),b=el('button','Buscar'),list=el('select'),label=el('label','Aluno'),out=el('div'),notice=el('p'),nav=el('div',undefined,'ederp-tools'),prev=el('button','Anterior'),next=el('button','Próxima');let page=1;list.setAttribute('aria-label','Selecionar aluno');b.type='submit';prev.type=next.type='button';label.append(list);nav.append(prev,next);f.append(q,b);c.append(f,label,nav,notice,out);
    async function load(){try{const d=await api('financeiro/alunos?page='+page+'&search='+encodeURIComponent(f.querySelector('input').value));list.replaceChildren(new Option('Selecione...',''));d.items.forEach(a=>list.append(new Option(a.nome+' · RA '+a.ra,a.idaluno)));prev.disabled=page===1;next.disabled=page*20>=d.total;notice.textContent=d.total+' alunos encontrados.';}catch(e){notice.textContent=e.message;}}
    f.onsubmit=e=>{e.preventDefault();page=1;load();};prev.onclick=()=>{page--;load();};next.onclick=()=>{page++;load();};list.onchange=async()=>{if(!list.value){out.replaceChildren();return;}try{out.replaceChildren(table(await api('alunos/'+list.value+'/financeiro'+periodQuery())));}catch(e){out.textContent=e.message;}};load();
  });
  document.querySelectorAll('[data-ederp-portal]').forEach(async portal=>{
    const scope=portal.dataset.ederpPortal;
    try{const students=await api('me/alunos');portal.replaceChildren(el('h2','Portal educacional'));if(!students.length){portal.append(el('p','Não há alunos vinculados à sua conta. Procure a secretaria.'));return;}
      const label=el('label','Aluno'),select=el('select');students.forEach(s=>{const o=el('option',s.nome+' · RA '+s.ra);o.value=s.idaluno;select.append(o);});label.append(select);portal.append(label);const contents=el('div');portal.append(contents);
      let generation=0;
      async function show(){const current=++generation,id=select.value;contents.replaceChildren();
        const sections=scope==='all'?['academic','finance','renew']:[scope];
        for(const section of sections){if(current!==generation)return;const box=el('section',undefined,'ederp-card');contents.append(box);
          try{if(section==='renew'){const offers=await api('alunos/'+id+'/ofertas-rematricula?codperiodo='+encodeURIComponent(EDERP.currentPeriod||''));if(current!==generation)return;box.append(el('h3','Rematrícula'));if(!offers.length)box.append(el('p','Nenhuma oferta disponível neste momento.'));
            offers.forEach(o=>{const card=form('Confirmar rematrícula','rematriculas',[],{idoferta:o.idoferta,idmatricula_origem:o.idmatricula_origem,versao_termo:o.versao_termo});const f=card.querySelector('form'),term=el('div',o.texto_termo,'ederp-term');f.prepend(term);
              const target=o.turmas[0],l=el('p','Destino definido pela escola: '+(target?.nome||'Não definido')+(target?.curso?' · '+target.curso:'')),s=el('input');s.type='hidden';s.name='idturma';s.value=target?.idturma||'';f.append(s);const planName=el('p'),countLabel=field('quantidade_parcelas','Número de parcelas','number'),count=countLabel.querySelector('input');count.min='1';count.max='120';count.value=o.numero_parcelas;const pid=el('input'),pv=el('input');pid.type=pv.type='hidden';pid.name='idplano';pv.name='plano_versao';function plan(){const t=o.turmas.find(t=>String(t.idturma)===s.value),p=t?.plano;planName.textContent=p?'Plano: '+p.nome+' · Anuidade R$ '+p.valor_anuidade+' · Primeiro vencimento: '+o.primeiro_vencimento:'Nenhuma turma com plano disponível.';pid.value=p?.idplano||'';pv.value=p?.versao||'';f.querySelector('button').disabled=!p;}s.onchange=plan;plan();f.insertBefore(planName,f.querySelector('button'));f.insertBefore(countLabel,f.querySelector('button'));f.append(pid,pv);f.insertBefore(el('p','A rematrícula registra o contrato. As parcelas serão geradas posteriormente pelo setor financeiro.'),f.querySelector('button'));
              const check=el('label','Li e aceito o termo da rematrícula.','ederp-check'),input=el('input');input.type='checkbox';input.name='aceite';input.required=true;check.prepend(input);f.insertBefore(l,f.querySelector('button'));f.insertBefore(check,f.querySelector('button'));box.append(card);});
          }else{const rows=await api('alunos/'+id+'/'+(section==='finance'?'financeiro':'matriculas')+'?codperiodo='+encodeURIComponent(EDERP.currentPeriod||''));if(current!==generation)return;box.append(el('h3',section==='finance'?'Financeiro':'Matrículas'),table(rows));}}
          catch(e){box.append(el('p',e.message));}
        }
      }
      select.onchange=show;document.addEventListener('ederp:refresh',show);show();
    }catch(e){portal.replaceChildren(el('p',e.message));}
  });
})();
