/* Password visibility only. Authentication remains a native POST to the school portal. */
(()=>{
 document.querySelectorAll('.erp-login-screen [data-login-toggle]').forEach(button=>{
  const input=document.getElementById(button.getAttribute('aria-controls'));if(!input)return;
  const label=button.getAttribute('aria-label');
  button.addEventListener('click',()=>{
   const visible=input.type==='password';input.type=visible?'text':'password';
   button.textContent=visible?'Ocultar':'Mostrar';button.setAttribute('aria-pressed',String(visible));button.setAttribute('aria-label',visible?label.replace(/^Mostrar /,'Ocultar '):label);
  });
 });
})();
