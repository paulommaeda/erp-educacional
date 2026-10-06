(() => {
'use strict';
let datasets;
const uppercaseFields=new Set(['nome','codigo','descricao','codpessoa_origem','ra','rg','rua','numero','complemento','bairro','cep','cidade','estado','profissao','religiao','igreja']);
document.addEventListener('change',e=>{const i=e.target;if(i.matches('input,textarea')&&uppercaseFields.has(i.name)&&i.dataset.preserveCase!=='1')i.value=i.value.toUpperCase();});
const node=(tag,text)=>{const x=document.createElement(tag);if(text!==undefined)x.textContent=text;return x;};
function field(parent,name,title,value='',type='text'){const label=node('label',title),i=node('input');i.name=name;i.type=type;i.value=value??'';label.append(i);parent.append(label);return i;}
function choice(parent,name,title,options,value){const label=node('label',title),s=node('select');s.name=name;for(const [v,t] of options){const o=node('option',t);o.value=v;s.append(o);}s.value=value??'';label.append(s);parent.append(label);return s;}
function extras(f,p,all=false,group=null){
 const defs=(EDERP.additionalFields||[]).filter(d=>Number(d.ativo)&&(all||Number(d.exibir_pessoa))&&(group===null||String(d.idgrupo)===String(group))&&(!EDERP.fieldGroups||EDERP.fieldGroups.some(g=>Number(g.ativo)&&String(g.idgrupo)===String(d.idgrupo))));
 const groups={};for(const d of defs){let target;if(all){target=f;}else if(d.secao==='identificacao'){target=f;}else if(d.secao==='pessoais'){target=f.querySelector('[name=rg]').closest('fieldset');}else if(d.secao==='endereco'){target=f.querySelector('[name=rua]').closest('fieldset');}else{groups.outros??=node('fieldset');groups.outros.dataset.section='outros';if(!groups.outros.parentNode){groups.outros.append(node('legend','Outros'));f.append(groups.outros);}target=groups.outros;}
 let i;const value=p.campos_adicionais?.[d.chave]??'';
 if(d.tipo==='selecao')i=choice(target,'extra__'+d.chave,d.nome,[['','Não informado'],...d.opcoes.split(/\r?\n/).filter(x=>x.trim()).map(x=>[x.trim(),x.trim()])],value);
 else if(d.tipo==='texto_longo'){const l=node('label',d.nome);i=node('textarea');i.name='extra__'+d.chave;i.value=value;l.append(i);target.append(l);}
 else{i=field(target,'extra__'+d.chave,d.nome,value,({numero:'number',data:'date',email:'email'})[d.tipo]||'text');if(d.tipo==='numero')i.step='any';}
 if(['texto','texto_longo'].includes(d.tipo))i.addEventListener('change',()=>i.value=i.value.toUpperCase());
 }
}
window.EDERPFields={extras,attach(f,p){
 f.classList.add('erp-person-fields');
 const box=node('fieldset'),legend=node('legend','Documentos e informações pessoais');box.append(legend);f.append(box);
 for(const [name,title] of [['rg','RG']])field(box,name,title,p[name]);
 choice(box,'sexo','Sexo',[['','Não informado'],['MASCULINO','MASCULINO'],['FEMININO','FEMININO']],p.sexo?.toUpperCase());
 const civil=choice(box,'idestado_civil','Estado civil',[['','Não informado']],p.idestado_civil);civil.disabled=true;let civilReady=false;
 const civilStatus=node('p','Carregando estados civis...');box.append(civilStatus);
 fetch(EDERP.root+'estados-civis',{credentials:'same-origin',cache:'no-store',headers:{'X-WP-Nonce':EDERP.nonce}}).then(r=>{if(!r.ok)throw Error('Não foi possível carregar os estados civis. Recarregue a página.');return r.json();}).then(rows=>{rows.forEach(r=>{const o=node('option',r.idestado_civil+' — '+r.nome);o.value=r.idestado_civil;civil.append(o);});civil.value=p.idestado_civil??'';civil.disabled=false;civilReady=true;civilStatus.textContent='';}).catch(e=>civilStatus.textContent=e.message);
 f.addEventListener('submit',e=>{if(!civilReady){e.preventDefault();e.stopImmediatePropagation();civilStatus.textContent='Aguarde carregar os estados civis ou recarregue a página.';}},true);
 for(const [name,title] of [['profissao','Profissão'],['religiao','Religião'],['igreja','Igreja']])field(box,name,title,p[name]);
 const address=node('fieldset');address.append(node('legend','Endereço'));f.append(address);
 for(const [name,title] of [['rua','Rua'],['numero','Número'],['complemento','Complemento'],['bairro','Bairro'],['cep','CEP']])field(address,name,title,p[name]);
 const country=choice(address,'pais','País',[['BR','Brasil']],p.pais||'BR');
 const region=choice(address,'estado','Estado',[],''),city=choice(address,'cidade','Cidade',[],'');
 const otherRegion=field(address,'estado_exterior','Estado / região',p.pais!=='BR'?p.estado:''),otherCity=field(address,'cidade_exterior','Cidade',p.pais!=='BR'?p.cidade:'');
 const status=node('p','Carregando países e municípios...');status.setAttribute('role','status');address.append(status);let ready=false;
 f.addEventListener('submit',e=>{if(!ready){e.preventDefault();e.stopImmediatePropagation();status.textContent='Aguarde carregar as localidades ou tente novamente.';}},true);
 datasets??=Promise.all(['paises','brasil'].map(x=>fetch(EDERP.assets+'localidades/'+x+'.json').then(r=>{if(!r.ok)throw Error('Não foi possível carregar as localidades. Recarregue a página.');return r.json();})));
 datasets.then(([countries,br])=>{
  const fill=(s,options,value)=>{s.replaceChildren();options.forEach(([v,t])=>{const o=node('option',t);o.value=v;s.append(o);});s.value=value||'';};
  fill(country,Object.entries(countries).map(([k,v])=>[k,v.toUpperCase()]),p.pais||'BR');
  fill(region,[['','Selecione...'],...Object.entries(br).map(([uf,v])=>[uf,v.nome.toUpperCase()+' ('+uf+')']).sort((a,b)=>a[1].localeCompare(b[1]))],p.estado);
  const cities=(value='')=>fill(city,[['','Selecione...'],...(br[region.value]?.cidades||[]).map(c=>[c.nome.toUpperCase(),c.nome.toUpperCase()])],value);
  const toggle=()=>{const brazil=country.value==='BR';region.disabled=city.disabled=!brazil;otherRegion.disabled=otherCity.disabled=brazil;region.parentElement.hidden=city.parentElement.hidden=!brazil;otherRegion.parentElement.hidden=otherCity.parentElement.hidden=brazil;region.name=brazil?'estado':'estado_brasil';city.name=brazil?'cidade':'cidade_brasil';otherRegion.name=brazil?'estado_exterior':'estado';otherCity.name=brazil?'cidade_exterior':'cidade';};
  region.onchange=()=>cities();country.onchange=toggle;cities(p.cidade?.toUpperCase());toggle();ready=true;status.textContent='';
  f.addEventListener('reset',()=>setTimeout(()=>{country.value='BR';region.value='';cities();toggle();},0));
 }).catch(e=>{status.textContent=e.message;status.setAttribute('role','alert');});
 const photo=node('fieldset');photo.append(node('legend','Foto / avatar WordPress'));const id=field(photo,'foto_attachment_id','',p.foto_attachment_id,'hidden'),img=node('img');img.width=96;img.height=96;img.alt='Foto da pessoa';img.hidden=true;photo.append(img);const select=node('button','Selecionar / enviar foto'),remove=node('button','Remover foto');select.type=remove.type='button';photo.append(select,remove);f.append(photo);
 const show=(url)=>{img.src=url;img.hidden=false;};
 select.onclick=()=>{if(!window.wp?.media)return;const frame=wp.media({title:'Foto da pessoa',multiple:false,library:{type:'image'},button:{text:'Usar foto'}});frame.on('select',()=>{const a=frame.state().get('selection').first().toJSON();id.value=a.id;show(a.sizes?.thumbnail?.url||a.url);});const dialog=f.closest('dialog');if(dialog?.open){dialog.dataset.media='open';dialog.close();frame.on('close',()=>{dialog.showModal();delete dialog.dataset.media;});}frame.open();};
 if(!window.wp?.media){select.disabled=true;photo.append(node('p','O envio de fotos exige acesso à biblioteca de mídia do WordPress.'));}
 if(id.value&&window.wp?.media){const a=wp.media.attachment(id.value);a.fetch().then(()=>show(a.get('sizes')?.thumbnail?.url||a.get('url')));}
 if(!f.dataset.skipExtras)extras(f,p);
 remove.onclick=()=>{id.value='';img.hidden=true;};f.addEventListener('reset',()=>{id.value='';img.hidden=true;});
}};
})();
