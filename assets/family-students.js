(() => {
'use strict';
const h=(tag,text,cls)=>{const e=document.createElement(tag);if(text!==undefined)e.textContent=text;if(cls)e.className=cls;return e;},key=()=>crypto.randomUUID();
const btn=(text,action,secondary=false)=>{const b=h('button',text,secondary?'erp-secondary':'');b.type='button';b.onclick=action;return b;};
const note=(text='',error=false)=>{const p=h('p',text,error?'erp-feedback error':'erp-feedback');p.setAttribute('role',error?'alert':'status');return p;};
const field=(f,name,title,type='text',value='')=>{const label=h('label',title),i=h(type==='textarea'?'textarea':'input');if(type!=='textarea')i.type=type;i.name=name;i.value=value??'';label.append(i);f.append(label);return i;};
const choice=(f,name,title,options,value)=>{const label=h('label',title),s=h('select');s.name=name;s.setAttribute('aria-label',title);for(const [v,t] of options)s.append(new Option(t,v));if(value!==undefined)s.value=String(value);label.append(s);f.append(label);return s;};
async function api(path,data,requestKey=key()){
 const multipart=data instanceof FormData,r=await fetch(EDERP.root+path,{method:data?'POST':'GET',credentials:'same-origin',cache:'no-store',headers:{'X-WP-Nonce':EDERP.nonce,...(data?{'Idempotency-Key':requestKey,...(!multipart?{'Content-Type':'application/json'}:{})}:{})},...(data?{body:multipart?data:JSON.stringify(data)}:{})});let j;try{j=await r.json();}catch{throw Error('Não foi possível obter a resposta. Confira sua conexão e tente novamente.');}if(!r.ok)throw Error(j.message||'Não foi possível concluir a operação.');return j;
}
function dialog(title){
 const e=h('dialog',undefined,'erp-form-dialog erp-family-dialog ederp'),header=h('header',undefined,'erp-modal-header'),heading=h('h2',title),body=h('div',undefined,'erp-modal-body'),close=btn('Fechar',()=>e.close(),true),opener=document.activeElement;heading.id='erp-family-'+key();e.setAttribute('aria-labelledby',heading.id);header.append(heading,close);e.append(header,body);(document.querySelector('.journey-app')||document.body).append(e);
 const busy=()=>!!e.querySelector('[data-saving="1"]');close.onclick=()=>{if(!busy())e.close();};e.addEventListener('cancel',event=>{if(busy())event.preventDefault();});e.addEventListener('close',()=>{e.remove();if(opener?.isConnected)opener.focus();});e.showModal();return {dialog:e,body};
}
function bind(form,path,body,done,label='Salvar'){
 const message=note(),submit=h('button',label);submit.type='submit';form.append(submit,message);let previous='',requestKey=key();
 form.onsubmit=async event=>{event.preventDefault();if(form.dataset.saving==='1'||!form.reportValidity())return;let disabled=[];
  try{const data=body(),signature=data instanceof FormData?JSON.stringify([...data].map(([n,v])=>[n,v instanceof File?[v.name,v.size,v.lastModified]:v])):JSON.stringify(data);if(signature!==previous){previous=signature;requestKey=key();}form.dataset.saving='1';disabled=[...form.querySelectorAll('button,input,select,textarea')].map(i=>[i,i.disabled]);disabled.forEach(([i])=>i.disabled=true);message.className='erp-feedback';message.textContent='Salvando...';const result=await api(typeof path==='function'?path():path,data,requestKey);message.textContent='Salvo com sucesso.';await done?.(result,message);}
  catch(error){message.className='erp-feedback error';message.textContent=error.message;}finally{form.dataset.saving='0';disabled.forEach(([i,state])=>i.disabled=state);}
 };
}
let localities;
async function contact(container,student,initial){
 const d=initial||await api('familia/alunos/'+student+'/contato');if(!container.isConnected)return;container.replaceChildren();const f=h('form',undefined,'ederp-form erp-family-contact-form'),p=d.contato;
 for(const [name,label,type,max] of [['email','E-mail','email',191],['telefone','Telefone / WhatsApp','tel',30],['rua','Rua','text',191],['numero','Número','text',30],['complemento','Complemento','text',191],['bairro','Bairro','text',100],['cep','CEP','text',20]]){const i=field(f,name,label,type,p[name]);i.maxLength=max;}
 const country=choice(f,'pais','País',[[p.pais||'BR',p.pais||'Brasil']],p.pais||'BR'),region=choice(f,'estado','Estado',[['','Selecione...']],''),city=choice(f,'cidade','Cidade',[['','Selecione...']],''),regionText=field(f,'estado','Estado / região','text',p.estado),cityText=field(f,'cidade','Cidade','text',p.cidade),loading=note('Carregando localidades...');regionText.maxLength=cityText.maxLength=100;let ready=false;f.append(loading);container.append(f);
 f.addEventListener('submit',event=>{if(!ready){event.preventDefault();event.stopImmediatePropagation();loading.textContent='Aguarde carregar as localidades.';}},true);
 bind(f,'familia/alunos/'+student+'/contato',()=>({...Object.fromEntries(new FormData(f)),versao:d.versao}),r=>{d.versao=r.versao;},'Salvar contatos');
 try{localities??=Promise.all(['paises','brasil'].map(name=>fetch(EDERP.assets+'localidades/'+name+'.json').then(r=>{if(!r.ok)throw Error('Não foi possível carregar as localidades. Reabra o cadastro.');return r.json();})));const [countries,br]=await localities;
  country.replaceChildren(...Object.entries(countries).map(([id,name])=>new Option(name,id)));country.value=p.pais||'BR';region.replaceChildren(new Option('Selecione...',''),...Object.entries(br).map(([id,v])=>new Option(v.nome,id)));region.value=p.estado||'';
  const cities=(value='')=>{city.replaceChildren(new Option('Selecione...',''),...(br[region.value]?.cidades||[]).map(v=>new Option(v.nome.toUpperCase(),v.nome.toUpperCase())));city.value=String(value||'').toUpperCase();};
  const toggle=()=>{const brazil=country.value==='BR';for(const i of [region,city]){i.disabled=!brazil;i.parentElement.hidden=!brazil;}for(const i of [regionText,cityText]){i.disabled=brazil;i.parentElement.hidden=brazil;}};region.onchange=()=>cities();country.onchange=toggle;cities(p.cidade);toggle();loading.textContent='';ready=true;
 }catch(error){localities=null;loading.className='erp-feedback error';loading.textContent=error.message;}
}
async function open(student){
 const modal=dialog('Dados do aluno'),nav=h('nav',undefined,'erp-family-tabs'),content=h('div');modal.body.append(note('Carregando aluno...'));
 try{const data=await api('familia/alunos/'+student+'/contato');if(!modal.dialog.isConnected)return;modal.body.replaceChildren(h('h3',data.nome),h('p','RA '+data.ra,'erp-muted'),nav,content);
  let busy=false;const show=async (section,initial)=>{if(busy||content.querySelector('[data-saving="1"]'))return;busy=true;nav.querySelectorAll('button').forEach(b=>{b.disabled=true;b.classList.toggle('active',b.dataset.section===section);b.setAttribute('aria-pressed',String(b.dataset.section===section));});content.replaceChildren(note('Carregando...'));try{if(section==='contato')await contact(content,student,initial);else await documents(content,student);}catch(error){content.replaceChildren(note(error.message,true));}finally{busy=false;nav.querySelectorAll('button').forEach(b=>b.disabled=false);}};
  for(const [id,label] of [['contato','Dados de contato'],['documentos','Documentos do aluno']]){const b=btn(label,()=>show(id),true);b.dataset.section=id;nav.append(b);}await show('contato',data);
 }catch(error){modal.body.replaceChildren(note(error.message,true));}
}
const labels={pendente:'Aguardando conferência',aprovado:'✓ OK · Aprovado',rejeitado:'Rejeitado · Envie novamente'};
function status(value){return h('span',labels[value]||'Não enviado','erp-document-status '+(value||'ausente'));}
async function download(row,staff=false){
 const m=dialog('Arquivo do aluno');m.body.append(note('Carregando arquivo...'));try{const file=await api((staff?'documentos-alunos/':'familia/documentos/')+row.iddocumento+'/arquivo');if(!m.dialog.isConnected)return;
  const bytes=Uint8Array.from(atob(file.conteudo),c=>c.charCodeAt(0)),blob=new Blob([bytes],{type:file.mime}),url=URL.createObjectURL(blob);m.dialog.addEventListener('close',()=>URL.revokeObjectURL(url),{once:true});const a=h('a','Baixar arquivo','erp-link-button');a.href=url;a.download=file.nome;m.body.replaceChildren(h('p',file.nome),a);
  if(file.mime.startsWith('image/')){const image=h('img');image.src=url;image.alt=file.nome;image.className='erp-document-preview';m.body.append(image);}else{const frame=h('iframe');frame.src=url;frame.title=file.nome;frame.className='erp-document-pdf';m.body.append(frame);}
 }catch(error){m.body.replaceChildren(note(error.message,true));}
}
function review(row,refresh){
 const m=dialog('Conferir documento'),f=h('form',undefined,'ederp-form');m.body.append(h('h3',row.tipo_documento||row.arquivo_nome),h('p',[row.aluno,row.ra?'RA '+row.ra:null].filter(Boolean).join(' · ')),btn('Abrir arquivo para conferir',()=>download(row,true),true),f);
 const decision=choice(f,'status','Decisão',[['','Selecione...'],['aprovado','Aprovar documento'],['rejeitado','Rejeitar e solicitar novo envio']],''),reason=field(f,'motivo','Motivo da rejeição','textarea');decision.required=true;reason.maxLength=1000;const toggle=()=>{reason.required=decision.value==='rejeitado';reason.parentElement.hidden=!reason.required;};decision.onchange=toggle;toggle();
 bind(f,'documentos-alunos/'+row.iddocumento+'/conferir',()=>({versao:row.versao,status:decision.value,...(decision.value==='rejeitado'?{motivo:reason.value}:{})}),async()=>{m.dialog.close();await refresh();},'Confirmar conferência');
}
async function documents(container,student,staff=false){
 container.replaceChildren(note('Carregando documentos...'));
 try{const rows=await api(staff?'documentos-alunos/alunos/'+student:'familia/alunos/'+student+'/documentos');if(!container.isConnected)return;container.replaceChildren();const grid=h('div',undefined,'erp-document-grid');container.append(grid);
  if(!rows.length){grid.append(note('O colégio ainda não cadastrou documentos para envio.'));return;}
  const multiple=new Set(rows.map(r=>r.colegio)).size>1;
  for(const r of rows){const card=h('section',undefined,'ederp-card erp-document-card'),d=r.documento;card.append(h('h3',r.nome),status(d?.status));if(multiple)card.append(h('p',r.colegio,'erp-muted'));if(r.obrigatorio)card.append(h('p','Documento obrigatório','erp-muted'));if(r.orientacoes)card.append(h('p',r.orientacoes));if(!r.ativo)card.append(note('Solicitação inativa para novos envios.'));
   if(d){card.append(h('p',d.arquivo_nome,'erp-document-filename'));if(d.status==='rejeitado')card.append(note('Motivo: '+d.motivo,true));else card.append(btn('Ver arquivo',()=>download(d,staff),true));if(staff&&d.status==='pendente')card.append(btn('Conferir documento',()=>review({...d,tipo_documento:r.nome},()=>documents(container,student,true))));}
   if(!staff&&r.pode_enviar){const f=h('form',undefined,'ederp-form erp-document-upload'),file=field(f,'documento','Selecionar arquivo','file');file.accept='.pdf,.jpg,.jpeg,.png,.webp';file.required=true;f.append(h('p','PDF, JPG, PNG ou WebP · Até 5 MB por arquivo.','erp-muted'));card.append(f);bind(f,'familia/alunos/'+r.idaluno+'/documentos/'+r.idtipo_documento,()=>{if(!file.files[0]||file.files[0].size>5242880)throw Error('Selecione um arquivo de até 5 MB.');const upload=new FormData();upload.append('documento',file.files[0]);return upload;},()=>documents(container,student),d?'Reenviar documento':'Enviar documento');}
   grid.append(card);
  }
 }catch(error){container.replaceChildren(note(error.message,true),btn('Tentar novamente',()=>documents(container,student,staff),true));}
}
async function settings(container){
 container.replaceChildren();const head=h('section',undefined,'ederp-card'),list=h('div');head.append(h('h2','Documentos solicitados aos alunos'),h('p','Cadastre os documentos deste colégio. Os envios serão conferidos pela secretaria.'));container.append(head,list);
 const edit=(row={})=>{const m=dialog(row.idtipo_documento?'Editar documento solicitado':'Cadastrar documento solicitado'),f=h('form',undefined,'ederp-form'),name=field(f,'nome','Nome do documento','text',row.nome);name.required=true;name.maxLength=120;name.dataset.preserveCase='1';const directions=field(f,'orientacoes','Orientações para o responsável','textarea',row.orientacoes);directions.maxLength=1000;directions.dataset.preserveCase='1';const order=field(f,'ordem','Ordem','number',row.ordem||0);order.min=0;order.max=10000;choice(f,'obrigatorio','Obrigatoriedade',[['0','Opcional'],['1','Obrigatório']],row.obrigatorio??0);choice(f,'ativo','Situação',[['1','Ativo'],['0','Inativo']],row.ativo??1);m.body.append(f);bind(f,'configuracoes/documentos-alunos',()=>({...Object.fromEntries(new FormData(f)),...(row.idtipo_documento?{idtipo_documento:row.idtipo_documento,versao:row.versao}:{})}),async()=>{m.dialog.close();await load();});};
 const remove=row=>{const m=dialog('Excluir tipo de documento'),f=h('form',undefined,'ederp-form');m.body.append(h('p','Excluir '+row.nome+'? Tipos com envios devem ser inativados para preservar o histórico.'),f);bind(f,'configuracoes/documentos-alunos/'+row.idtipo_documento+'/excluir',()=>({versao:row.versao}),async()=>{m.dialog.close();await load();},'Excluir');};
 const load=async()=>{list.replaceChildren(note('Carregando tipos de documento...'));try{const rows=await api('configuracoes/documentos-alunos');list.replaceChildren();for(const r of rows){const c=h('section',undefined,'ederp-card erp-document-type');c.append(h('h3',r.nome),h('p',[Number(r.ativo)?'Ativo':'Inativo',Number(r.obrigatorio)?'Obrigatório':'Opcional','Ordem '+r.ordem].join(' · ')),h('p',r.orientacoes||''),btn('Editar',()=>edit(r),true),btn('Excluir',()=>remove(r),true));list.append(c);}if(!rows.length)list.append(note('Cadastre o primeiro documento solicitado pelo colégio.'));}catch(error){list.replaceChildren(note(error.message,true));}};
 head.append(btn('Cadastrar documento',()=>edit()));await load();
}
async function queue(container){
 container.replaceChildren(h('h1','Documentos dos alunos'));const filters=h('form',undefined,'ederp-tools erp-document-filters'),state=choice(filters,'status','Situação',[['pendente','Aguardando conferência'],['aprovado','Aprovados'],['rejeitado','Rejeitados'],['todos','Todos']],'pendente'),search=field(filters,'search','Aluno ou RA'),type=choice(filters,'idtipo_documento','Documento',[['0','Todos os documentos']],'0'),submit=h('button','Filtrar'),results=h('div'),navigation=h('div',undefined,'ederp-tools'),count=note();submit.type='submit';filters.append(submit);container.append(filters,results,navigation);let page=1,busy=false;
 const prev=btn('Anterior',()=>{page--;load();},true),next=btn('Próxima',()=>{page++;load();},true);navigation.append(prev,count,next);
 const load=async()=>{if(busy)return;busy=true;prev.disabled=next.disabled=submit.disabled=true;results.replaceChildren(note('Carregando envios...'));try{const data=await api('documentos-alunos?'+new URLSearchParams({status:state.value,search:search.value,idtipo_documento:type.value,page})),selected=type.value;type.replaceChildren(new Option('Todos os documentos','0'),...(data.tipos||[]).map(r=>new Option(r.nome,r.idtipo_documento)));type.value=selected;results.replaceChildren();const grid=h('div',undefined,'erp-document-grid');results.append(grid);for(const row of data.items){const c=h('section',undefined,'ederp-card erp-document-card');c.append(h('h2',row.aluno),h('p','RA '+row.ra),h('h3',row.tipo_documento),status(row.status),h('p',row.arquivo_nome,'erp-document-filename'));if(row.status!=='rejeitado')c.append(btn('Ver arquivo',()=>download(row,true),true));if(row.status==='pendente')c.append(btn('Conferir documento',()=>review(row,load)));if(row.motivo)c.append(h('p','Motivo: '+row.motivo));grid.append(c);}if(!data.items.length)results.append(note('Nenhum envio encontrado.'));count.textContent='Página '+page+' · '+data.total+' envio(s)';prev.disabled=page===1;next.disabled=page*20>=data.total;}
  catch(error){results.replaceChildren(note(error.message,true),btn('Tentar novamente',load,true));}finally{busy=false;submit.disabled=false;}
 };
 filters.onsubmit=event=>{event.preventDefault();if(busy)return;page=1;load();};await load();
}
window.EDERPFamily={open,documents,settings,queue};
const host=document.querySelector('[data-erp-documents]');if(host)queue(host).catch(error=>host.replaceChildren(note(error.message,true)));
})();
