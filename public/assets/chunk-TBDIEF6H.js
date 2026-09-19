import{a as i}from"./chunk-2LL6R7CL.js";import{a as t}from"./chunk-CW345KIZ.js";var $=5;function E(n){if(typeof n=="number"&&n>=0)return n;let c=window.TCMS_CONFIG?.confirmCountdown;return typeof c=="number"&&c>=0?c:$}t(E,"resolveCountdown");function b(n={}){let{title:c="",message:g="Are you sure?",confirmLabel:C=i("confirm.yes_sure",{},"Yes, I'm sure"),cancelLabel:y=i("confirm.cancel",{},"Cancel"),countdown:v=null,element:h=null}=n;if(h?.closest?.(".no-delete-confirm"))return Promise.resolve(!0);let l=E(v);return new Promise(k=>{let e=document.createElement("dialog");e.className="cms-modal small cms-confirm";let w=c?`<h2>${r(c)}</h2>`:"";e.innerHTML=`
			${w}
			<p class="cms-confirm-message">${r(g)}</p>
			<div class="cms-confirm-buttons">
				<button type="button" class="dash-button transparent cms-confirm-cancel">${r(y)}</button>
				<button type="button" class="dash-button cms-confirm-ok" ${l>0?"disabled":""}>
					<span class="cms-confirm-ok-label">${r(C)}</span>
					<span class="cms-confirm-ok-counter"></span>
				</button>
			</div>
		`,document.body.appendChild(e);let u=e.querySelector(".cms-confirm-ok"),f=e.querySelector(".cms-confirm-cancel"),L=e.querySelector(".cms-confirm-ok-counter"),o=l,s=null,d=t(()=>{L.textContent=o>0?` (${o})`:""},"renderCounter"),p=t(()=>{s!==null&&(clearInterval(s),s=null)},"stopTimer"),a=t(m=>{p(),e.close(),e.remove(),k(m)},"cleanup");l>0&&(d(),s=setInterval(()=>{o-=1,d(),o<=0&&(p(),u.disabled=!1)},1e3)),u.addEventListener("click",()=>a(!0)),f.addEventListener("click",()=>a(!1)),e.addEventListener("cancel",m=>{m.preventDefault(),a(!1)}),e.showModal(),f.focus()})}t(b,"tcmsConfirm");function r(n){return String(n).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#039;")}t(r,"escapeHtml");globalThis.tcmsConfirm=b;export{b as a};
//# sourceMappingURL=chunk-TBDIEF6H.js.map
