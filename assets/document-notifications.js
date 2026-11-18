(() => {
  'use strict';
  const h=(tag,text,cls)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=text;if(cls)n.className=cls;return n;};
  const button=(label,action)=>{const b=h('button',label);b.type='button';b.onclick=action;return b;};
  async function api(path,data){const response=await fetch(EDERP.root+path,{method:data?'POST':'GET',credentials:'same-origin',cache:'no-store',headers:{'X-WP-Nonce':EDERP.nonce,...(data?{'Content-Type':'application/json','Idempotency-Key':crypto.randomUUID()}:{})},...(data?{body:JSON.stringify(data)}:{})});const body=await response.json();if(!response.ok)throw Error(body.message||'Não foi possível concluir.');return body;}
  function field(parent,name,label,value,type='text'){const row=h('label',label),input=h(type==='textarea'?'textarea':'input');if(type!=='textarea')input.type=type;input.name=name;input.value=value??'';input.dataset.preserveCase='1';row.append(input);parent.append(row);return input;}
  function select(parent,name,label,items,value){const row=h('label',label),input=h('select');input.name=name;for(const [key,title]of items)input.append(new Option(title,key));input.value=String(value);row.append(input);parent.append(row);return input;}
  async function settings(parent){
    parent.replaceChildren(h('p','Carregando notificações...'));
    try{
      const payload=await api('configuracoes/documentos-emails'),config=payload.configuracao;let version=payload.versao;
      parent.replaceChildren(h('h2','E-mails e lembretes de documentos'),h('p','Estas configurações valem para a coligada de trabalho. O colégio aparece como identidade visual; o serviço de e-mail do WordPress faz os envios.'));
      const form=h('form',undefined,'ederp-form erp-document-mail-form'),status=h('p','','erp-feedback');status.setAttribute('role','status');
      const onOff=[['1','Ativado'],['0','Desativado']];
      const received=select(form,'recebido_ativo','Avisar a equipe quando a família enviar um documento',onOff,config.recebido_ativo),emails=field(form,'emails_equipe','E-mails da equipe que receberá os avisos',config.emails_equipe.join('\n'),'textarea');emails.maxLength=6000;
      form.append(h('p','Informe um e-mail por linha. Ative o aviso após definir os destinatários. Envios feitos pela secretaria entram na análise sem gerar esse aviso à própria equipe.','erp-muted'));
      const result=select(form,'resultado_ativo','Avisar responsáveis sobre aprovação ou rejeição',onOff,config.resultado_ativo),reminders=select(form,'lembretes_ativos','Lembrar documentos pendentes a cada dois dias',onOff,config.lembretes_ativos),guardians=select(form,'responsaveis','Responsáveis que receberão os avisos',[['ambos','Todos os responsáveis com acesso aos documentos'],['academico','Somente responsáveis acadêmicos'],['financeiro','Somente responsáveis financeiros']],config.responsaveis);
      form.append(h('p','O primeiro lembrete é programado para 48 horas após identificar uma pendência. A rejeição inicia um novo prazo de 48 horas. Os lembretes param quando os arquivos forem enviados; documentos aguardando análise não são cobrados.'));
      const models={};
      for(const [type,title]of Object.entries(payload.tipos)){
        const details=h('details',undefined,'erp-document-mail-model'),body=h('div');details.append(h('summary',title),body);const subject=field(body,'assunto_'+type,'Assunto',config.modelos[type].assunto),copy=field(body,'corpo_'+type,'Mensagem',config.modelos[type].corpo,'textarea');subject.required=copy.required=true;subject.maxLength=250;copy.maxLength=12000;
        const tokens=h('details'),buttons=h('div',undefined,'erp-document-mail-tokens');tokens.append(h('summary','Campos disponíveis — clique para inserir na mensagem'),buttons);
        for(const [name,label]of Object.entries(payload.shortcodes)){const b=button('['+name+']',()=>{copy.setRangeText('['+name+']',copy.selectionStart,copy.selectionEnd,'end');copy.focus();copy.dispatchEvent(new Event('input',{bubbles:true}));});b.title=label;buttons.append(b);}body.append(tokens);form.append(details);models[type]={subject,copy};
      }
      const save=h('button','Salvar e-mails e lembretes');save.type='submit';form.append(save,status);parent.append(form);let busy=false;
      form.onsubmit=async event=>{event.preventDefault();if(busy||!form.reportValidity())return;busy=true;save.disabled=true;status.textContent='Salvando...';try{const body={versao:version,recebido_ativo:received.value,resultado_ativo:result.value,lembretes_ativos:reminders.value,responsaveis:guardians.value,emails_equipe:emails.value,modelos:Object.fromEntries(Object.entries(models).map(([type,{subject,copy}])=>[type,{assunto:subject.value,corpo:copy.value}]))};const saved=await api('configuracoes/documentos-emails',body);version=saved.versao;status.textContent='E-mails e lembretes atualizados.';}catch(error){status.textContent=error.message;}finally{busy=false;save.disabled=false;}};
      const log=h('section',undefined,'erp-document-mail-log');parent.append(log);let page=1;
      const load=async()=>{log.replaceChildren(h('p','Carregando histórico...'));try{const data=await api('configuracoes/documentos-emails/historico?page='+page);log.replaceChildren(h('h3','Histórico de notificações'));
        const states={pendente:'Aguardando envio',enviando:'Envio iniciado, aguardando confirmação',enviado:'Encaminhado pelo WordPress',falha:'Falha no envio',cancelado:'Cancelado por mudança de vínculo, configuração ou pendência'};
        for(const row of data.items){const card=h('article',undefined,'ederp-card erp-document-mail-entry');card.append(h('strong',row.aluno+' · RA '+row.ra),h('p',(payload.tipos[row.tipo]||row.tipo)+' · '+row.destinatario),h('p',states[row.status]||row.status));if(row.enviado_em)card.append(h('small',new Date(row.enviado_em.replace(' ','T')+'Z').toLocaleString('pt-BR')));if(row.ultimo_erro)card.append(h('p',row.ultimo_erro));if(row.status==='falha')card.append(button('Tentar enviar novamente',async()=>{try{await api('configuracoes/documentos-emails/'+row.idnotificacao+'/tentar',{});await load();}catch(error){status.textContent=error.message;}}));log.append(card);}
        if(!data.items.length)log.append(h('p','Nenhuma notificação registrada.'));
        const nav=h('div',undefined,'ederp-tools'),prev=button('Anterior',()=>{page--;load();}),next=button('Próxima',()=>{page++;load();});prev.disabled=page===1;next.disabled=page*20>=data.total;nav.append(prev,h('span','Página '+page+' · '+data.total+' notificação(ões)'),next);log.append(nav);
      }catch(error){log.replaceChildren(h('p',error.message),button('Tentar novamente',load));}};await load();
    }catch(error){parent.replaceChildren(h('p',error.message),button('Tentar novamente',()=>settings(parent)));}
  }
  function open(){const dialog=h('dialog',undefined,'erp-form-dialog erp-family-dialog ederp'),head=h('header',undefined,'erp-modal-header'),body=h('div',undefined,'erp-modal-body'),close=button('Fechar',()=>dialog.close()),opener=document.activeElement;head.append(h('h2','Notificações de documentos'),close);dialog.append(head,body);document.body.append(dialog);dialog.addEventListener('close',()=>{dialog.remove();if(opener?.isConnected)opener.focus();});dialog.showModal();settings(body);}
  window.EDERPDocumentNotifications=Object.freeze({settings,open});
})();
