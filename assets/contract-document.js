(() => {
  'use strict';
  const tags=new Set('p div span br hr strong b em i u s strike sub sup h1 h2 h3 h4 h5 h6 blockquote pre ul ol li table caption thead tbody tfoot tr th td a'.split(' '));
  const drop=new Set('script style iframe object embed form input textarea button select option svg math audio video img link meta base template noscript'.split(' '));
  const styles=new Set('text-align font-weight font-style text-decoration font-family font-size line-height text-indent margin margin-top margin-right margin-bottom margin-left padding padding-top padding-right padding-bottom padding-left width max-width min-width height border border-top border-right border-bottom border-left border-width border-top-width border-bottom-width border-style border-color border-collapse color background-color vertical-align list-style-type page-break-before page-break-after page-break-inside'.split(' '));
  function render(text,format){
    const out=document.createElement(format==='html'?'div':'pre');out.className='erp-contract-text'+(format==='html'?' erp-contract-html':'');
    if(format!=='html'){out.textContent=text??'';return out;}
    const source=String(text??'');if(source.length>400000){out.textContent='Conteúdo muito extenso para a prévia.';return out;}
    const template=document.createElement('template');template.innerHTML=source;let count=0;
    function copy(node,parent,depth){
      if(++count>12000||depth>48)throw Error('Simplifique a estrutura HTML do contrato.');
      if(node.nodeType===Node.TEXT_NODE){parent.append(document.createTextNode(node.textContent));return;}
      if(node.nodeType!==Node.ELEMENT_NODE)return;const tag=node.localName;if(drop.has(tag))return;
      let target=parent;
      if(tags.has(tag)){
        target=document.createElement(tag);
        for(const name of ['colspan','rowspan'])if(['td','th'].includes(tag)&&/^[1-9][0-9]?$/.test(node.getAttribute(name)||''))target.setAttribute(name,node.getAttribute(name));
        for(const name of ['width','border','cellpadding','cellspacing'])if(['table','td','th'].includes(tag)&&/^[0-9]{1,3}%?$/.test(node.getAttribute(name)||''))target.setAttribute(name,node.getAttribute(name));
        if(tag==='ol'){if(/^-?[0-9]{1,4}$/.test(node.getAttribute('start')||''))target.setAttribute('start',node.getAttribute('start'));if(['1','a','A','i','I'].includes(node.getAttribute('type')))target.setAttribute('type',node.getAttribute('type'));}
        const align=node.getAttribute('align');if(['left','center','right','justify'].includes(align))target.style.textAlign=align;
        for(const rule of (node.getAttribute('style')||'').split(';')){
          const at=rule.indexOf(':');if(at<0)continue;const key=rule.slice(0,at).trim().toLowerCase(),value=rule.slice(at+1).trim();
          if(styles.has(key)&&value.length<=150&&/^[- a-z0-9.'"%,#]+$/i.test(value)&&!/(?:url|expression|javascript|behavior|binding)/i.test(value))target.style.setProperty(key,value);
        }
        if(tag==='a'){
          const href=node.getAttribute('href')||'';if(/^(?:https?:\/\/|mailto:|tel:)[^\s<>"\x00-\x1f]+$/i.test(href)){target.setAttribute('href',href);target.setAttribute('rel','noopener noreferrer');target.target='_blank';}
          if(node.hasAttribute('title'))target.title=node.getAttribute('title');
        }
        parent.append(target);
      }
      for(const child of node.childNodes)copy(child,target,depth+1);
    }
    try{for(const child of template.content.childNodes)copy(child,out,0);}catch(error){out.replaceChildren(document.createTextNode(error.message));}
    return out;
  }
  window.EDERPContractDocument=Object.freeze({render});
})();
