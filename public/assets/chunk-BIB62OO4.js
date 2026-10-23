import{a as O}from"./chunk-PK7HA5OZ.js";import{a as D}from"./chunk-COSB2ZMG.js";import{a as I,b as C}from"./chunk-WKJYEEBM.js";import{a as q}from"./chunk-NC52C2BS.js";import{a as i}from"./chunk-N5O52Q2M.js";function U(o){let a=o?.url;return typeof a=="function"?a():a}i(U,"getUploadUrl");function P(o){let a=U(o);if(!a)return null;let e=a.match(/^(.*\/upload\/[^/]+\/[^/]+\/[^/]+)(?:\/(.+))?$/);if(!e)return{url:a,params:new URLSearchParams};let[,u,c]=e,r=new URLSearchParams;return c&&r.set("path",c),{url:u,params:r}}i(P,"getListUrl");function X(o){return new Promise(a=>{let e=new Image,u=URL.createObjectURL(o);e.onload=()=>{a({width:e.width,height:e.height}),URL.revokeObjectURL(u)},e.onerror=()=>{a({width:0,height:0}),URL.revokeObjectURL(u)},e.src=u})}i(X,"getImageDimensions");async function j(o,a){if(!a||Object.keys(a).length===0)return{valid:!0};if(o.type.startsWith("image/")){let c=await X(o);o.width=c.width,o.height=c.height}let e=new O(a);return e.processRules(o,0)?{valid:!0}:{valid:!1,errors:e.errors}}i(j,"validateFile");function z(o,a){let e=new FormData;return e.append("file",o),a?.imagePreset&&e.append("p",a.imagePreset),e}i(z,"buildFormData");function w(){let o=document.querySelector('meta[name="csrf-token"]');return o?o.getAttribute("content"):null}i(w,"getCsrfToken");function W(o){let a=w();a&&o.setRequestHeader("X-CSRF-Token",a)}i(W,"applyCsrfHeader");function B(o,a,e){return new Promise((u,c)=>{if(!a){c(new Error("Save the form before uploading."));return}let r=new XMLHttpRequest;r.open("POST",a),W(r),r.addEventListener("load",()=>{if(r.status>=200&&r.status<300)try{u(JSON.parse(r.responseText))}catch{c(new Error("Failed to parse upload response"))}else c(new Error(`Upload failed: ${r.status}`))}),r.addEventListener("error",()=>c(new Error("Upload error"))),r.send(z(o,e))})}i(B,"uploadFile");function R(o,a,e,{onProgress:u,onSuccess:c,onError:r}){if(!a){r?.("Save the form before uploading.");return}let d=new XMLHttpRequest;d.open("POST",a),W(d),d.upload.addEventListener("progress",y=>{y.lengthComputable&&u?.(Math.round(y.loaded/y.total*100))}),d.addEventListener("load",()=>{if(d.status>=200&&d.status<300)try{c?.(JSON.parse(d.responseText))}catch{r?.("Failed to parse upload response")}else r?.(`Upload failed: ${d.status}`)}),d.addEventListener("error",()=>r?.("Upload error")),d.send(z(o,e))}i(R,"uploadFileWithProgress");function ee(o,a){let e=document.createElement("dialog");e.className="ste-dialog ste-dialog--image-manager",e.innerHTML=`
		<div class="ste-dialog-header">
			<h3>Insert Image</h3>
			<button type="button" class="ste-dialog-close" aria-label="Close">&times;</button>
		</div>
		<div class="ste-dialog-tabs">
			<button type="button" class="ste-dialog-tab is-active" data-tab="upload">Upload</button>
			<button type="button" class="ste-dialog-tab" data-tab="images">Images</button>
		</div>
		<div class="ste-dialog-body">
			<div class="ste-dialog-panel is-active" data-panel="upload">
				<div class="ste-upload-zone">
					<input type="file" accept="image/*" class="ste-upload-input" />
					<p>Click or drag an image here to upload</p>
				</div>
				<div class="ste-upload-progress" style="display:none;">
					<div class="ste-upload-progress-bar"></div>
					<span class="ste-upload-progress-text">Uploading...</span>
				</div>
				<div class="ste-upload-error" style="display:none;"></div>
			</div>
			<div class="ste-dialog-panel" data-panel="images">
				<div class="ste-image-loading">Loading images...</div>
				<div class="ste-image-grid" style="display:none;"></div>
				<div class="ste-image-empty" style="display:none;">No images uploaded yet</div>
			</div>
		</div>
		<div class="ste-dialog-footer">
			<input type="text" class="ste-alt-input" placeholder="Alt text (optional)" />
			<div class="ste-dialog-actions">
				<button type="button" class="ste-dialog-btn ste-dialog-btn--cancel">Cancel</button>
				<button type="button" class="ste-dialog-btn ste-dialog-btn--insert">Insert</button>
			</div>
		</div>
	`;let u=!1;e.querySelectorAll(".ste-dialog-tab").forEach(t=>{t.addEventListener("click",()=>{e.querySelectorAll(".ste-dialog-tab").forEach(s=>s.classList.remove("is-active")),e.querySelectorAll(".ste-dialog-panel").forEach(s=>s.classList.remove("is-active")),t.classList.add("is-active"),e.querySelector(`[data-panel="${t.dataset.tab}"]`).classList.add("is-active"),t.dataset.tab==="images"&&H()})});let c=e.querySelector(".ste-upload-input"),r=e.querySelector(".ste-upload-zone"),d=e.querySelector(".ste-upload-progress"),y=e.querySelector(".ste-upload-progress-bar"),k=e.querySelector(".ste-upload-progress-text"),v=e.querySelector(".ste-upload-error"),f=e.querySelector(".ste-image-grid"),x=e.querySelector(".ste-image-loading"),L=e.querySelector(".ste-image-empty"),_=a.rules||{},h=null,m=null;function b(t){let s=Array.isArray(t)?t:[t];v.textContent=s.join(". "),v.style.display=""}i(b,"showError");function T(){v.textContent="",v.style.display="none"}i(T,"clearError"),r.addEventListener("dragover",t=>{t.preventDefault(),r.classList.add("is-dragover")}),r.addEventListener("dragleave",()=>{r.classList.remove("is-dragover")}),r.addEventListener("drop",t=>{t.preventDefault(),r.classList.remove("is-dragover");let s=t.dataTransfer.files[0];s&&s.type.startsWith("image/")&&F(s)}),c.addEventListener("change",()=>{c.files[0]&&F(c.files[0])});async function F(t){T();let s=await j(t,_);if(!s.valid){b(s.errors);return}let n=U(a);d.style.display="",R(t,n,a,{onProgress(p){y.style.width=`${p}%`,k.textContent=`Uploading... ${p}%`},onSuccess(p){d.style.display="none",h=p.link;let g=URL.createObjectURL(t);r.innerHTML=`<img class="ste-upload-preview" src="${g}" alt="${t.name}" />`,u=!1},onError(p){d.style.display="none",b(p)}})}i(F,"handleUpload");async function H(t=!1){if(u&&!t)return;x.style.display="",f.style.display="none",L.style.display="none",m=null;let s=P(a);if(!s)return;let n=s.params;n.set("type","image"),a.imagePreset&&n.set("preset",a.imagePreset);let p=`${s.url}?${n}`;try{let g=await fetch(p,{method:"GET"});if(!g.ok)throw new Error(`Failed to load images: ${g.status}`);let S=(await g.json()).files||[];x.style.display="none",S.length===0?(L.style.display="",f.style.display="none"):(L.style.display="none",f.style.display="",M(S)),u=!0}catch(g){x.style.display="none",L.textContent="Failed to load images",L.style.display="",console.error("Image manager load error:",g)}}i(H,"loadImages");function M(t){f.innerHTML="",m=null,t.forEach(s=>{let n=document.createElement("div");n.className="ste-image-card",n.dataset.url=s.url,n.dataset.name=s.name,n.innerHTML=`
				<div class="ste-image-card__thumb-wrap">
					<img class="ste-image-card__thumb" src="${s.thumbnail}" alt="${s.name}" loading="lazy" />
					<button type="button" class="ste-image-card__delete" aria-label="Delete image" title="Delete">\u2715</button>
				</div>
				<span class="ste-image-card__name" title="${s.name}">${s.name}</span>
			`,n.addEventListener("click",p=>{p.target.closest(".ste-image-card__delete")||(f.querySelectorAll(".ste-image-card").forEach(g=>g.classList.remove("is-selected")),n.classList.add("is-selected"),m=s.url)}),n.addEventListener("dblclick",p=>{p.target.closest(".ste-image-card__delete")||(m=s.url,E())}),n.querySelector(".ste-image-card__delete").addEventListener("click",p=>{p.stopPropagation(),$(s.name,n)}),f.appendChild(n)})}i(M,"renderImageGrid");async function $(t,s){let g=o.getContent().includes(t)?q("confirm.image_in_use"):q("confirm.delete_label",{label:"image"});if(!await D({message:g}))return;let S=`${U(a)}/${encodeURIComponent(t)}`;fetch(S,{method:"DELETE",headers:w()?{"X-CSRF-Token":w()}:{}}).then(C).then(A=>{s.remove(),f.children.length===0&&(f.style.display="none",L.textContent="No images uploaded yet",L.style.display=""),m&&s.dataset.url===m&&(m=null)}).catch(A=>{console.error("Delete image error:",A),alert(I(A,"error.delete_image"))})}i($,"handleDeleteImage");function E(){let t=e.querySelector(".ste-alt-input").value,s=e.querySelector(".ste-dialog-panel.is-active")?.dataset.panel;s==="upload"&&h?(o.insertImage({src:h,alt:t}),l()):s==="images"&&m&&(o.insertImage({src:m,alt:t}),l())}i(E,"insertImage");let l=i(()=>e.close(),"close");e.querySelector(".ste-dialog-close").addEventListener("click",l),e.querySelector(".ste-dialog-btn--cancel").addEventListener("click",l),e.addEventListener("click",t=>{t.target===e&&l()}),e.querySelector(".ste-dialog-btn--insert").addEventListener("click",E),e.addEventListener("close",()=>e.remove()),document.body.appendChild(e),e.showModal()}i(ee,"createImageDialog");function re(o,a){let e=document.createElement("dialog");e.className="ste-dialog ste-dialog--file-manager",e.innerHTML=`
		<div class="ste-dialog-header">
			<h3>Insert File Link</h3>
			<button type="button" class="ste-dialog-close" aria-label="Close">&times;</button>
		</div>
		<div class="ste-dialog-tabs">
			<button type="button" class="ste-dialog-tab is-active" data-tab="upload">Upload</button>
			<button type="button" class="ste-dialog-tab" data-tab="files">Files</button>
		</div>
		<div class="ste-dialog-body">
			<div class="ste-dialog-panel is-active" data-panel="upload">
				<div class="ste-upload-zone">
					<input type="file" class="ste-upload-input" />
					<p>Click or drag a file here to upload</p>
				</div>
				<div class="ste-upload-progress" style="display:none;">
					<div class="ste-upload-progress-bar"></div>
					<span class="ste-upload-progress-text">Uploading...</span>
				</div>
				<div class="ste-upload-error" style="display:none;"></div>
			</div>
			<div class="ste-dialog-panel" data-panel="files">
				<div class="ste-file-loading">Loading files...</div>
				<div class="ste-file-list" style="display:none;"></div>
				<div class="ste-file-empty" style="display:none;">No files uploaded yet</div>
			</div>
		</div>
		<div class="ste-dialog-footer">
			<input type="text" class="ste-link-text-input" placeholder="Link text (optional)" />
			<div class="ste-dialog-actions">
				<button type="button" class="ste-dialog-btn ste-dialog-btn--cancel">Cancel</button>
				<button type="button" class="ste-dialog-btn ste-dialog-btn--insert">Insert</button>
			</div>
		</div>
	`;let u=!1;e.querySelectorAll(".ste-dialog-tab").forEach(l=>{l.addEventListener("click",()=>{e.querySelectorAll(".ste-dialog-tab").forEach(t=>t.classList.remove("is-active")),e.querySelectorAll(".ste-dialog-panel").forEach(t=>t.classList.remove("is-active")),l.classList.add("is-active"),e.querySelector(`[data-panel="${l.dataset.tab}"]`).classList.add("is-active"),l.dataset.tab==="files"&&F()})});let c=null,r=null,d=null,y=null,k=e.querySelector(".ste-upload-input"),v=e.querySelector(".ste-upload-zone"),f=e.querySelector(".ste-upload-progress"),x=e.querySelector(".ste-upload-progress-bar"),L=e.querySelector(".ste-upload-progress-text"),_=e.querySelector(".ste-upload-error"),h=e.querySelector(".ste-file-list"),m=e.querySelector(".ste-file-loading"),b=e.querySelector(".ste-file-empty");v.addEventListener("dragover",l=>{l.preventDefault(),v.classList.add("is-dragover")}),v.addEventListener("dragleave",()=>{v.classList.remove("is-dragover")}),v.addEventListener("drop",l=>{l.preventDefault(),v.classList.remove("is-dragover"),l.dataTransfer.files[0]&&T(l.dataTransfer.files[0])}),k.addEventListener("change",()=>{k.files[0]&&T(k.files[0])});function T(l){let t=U(a);r=l.name,_.style.display="none",f.style.display="",R(l,t,a,{onProgress(s){x.style.width=`${s}%`,L.textContent=`Uploading... ${s}%`},onSuccess(s){f.style.display="none",c=s.link,v.innerHTML=`<p>Uploaded: ${l.name}</p>`,u=!1},onError(s){f.style.display="none",_.textContent=s,_.style.display=""}})}i(T,"handleUpload");async function F(l=!1){if(u&&!l)return;m.style.display="",h.style.display="none",b.style.display="none",d=null,y=null;let t=P(a);if(!t)return;t.params.set("type","file");let s=`${t.url}?${t.params}`;try{let n=await fetch(s,{method:"GET"});if(!n.ok)throw new Error(`Failed to load files: ${n.status}`);let g=(await n.json()).files||[];m.style.display="none",g.length===0?(b.style.display="",h.style.display="none"):(b.style.display="none",h.style.display="",H(g)),u=!0}catch(n){m.style.display="none",b.textContent="Failed to load files",b.style.display="",console.error("File manager load error:",n)}}i(F,"loadFiles");function H(l){h.innerHTML="",d=null,y=null,l.forEach(t=>{let s=document.createElement("div");s.className="ste-file-row",s.dataset.url=t.url,s.dataset.name=t.name,s.innerHTML=`
				<span class="ste-file-row__name" title="${t.name}">${t.name}</span>
				<button type="button" class="ste-file-row__delete" aria-label="Delete" title="Delete">\u2715</button>
			`,s.addEventListener("click",n=>{n.target.closest(".ste-file-row__delete")||(h.querySelectorAll(".ste-file-row").forEach(p=>p.classList.remove("is-selected")),s.classList.add("is-selected"),d=t.url,y=t.name)}),s.addEventListener("dblclick",n=>{n.target.closest(".ste-file-row__delete")||(d=t.url,y=t.name,$())}),s.querySelector(".ste-file-row__delete").addEventListener("click",n=>{n.stopPropagation(),M(t.name,s)}),h.appendChild(s)})}i(H,"renderFileList");async function M(l,t){let p=o.getContent().includes(l)?q("confirm.file_in_use"):q("confirm.delete_label",{label:"file"});if(!await D({message:p}))return;let N=`${U(a)}/${encodeURIComponent(l)}`;fetch(N,{method:"DELETE",headers:w()?{"X-CSRF-Token":w()}:{}}).then(C).then(S=>{t.remove(),h.children.length===0&&(h.style.display="none",b.textContent="No files uploaded yet",b.style.display=""),d&&t.dataset.url===d&&(d=null,y=null)}).catch(S=>{console.error("Delete file error:",S),alert(I(S,"error.delete_file"))})}i(M,"handleDeleteFile");function $(){let l=e.querySelector(".ste-dialog-panel.is-active")?.dataset.panel,t=e.querySelector(".ste-link-text-input");if(l==="upload"&&c){let s=t.value||r||"Download file";o.insertFileLink({href:c,text:s}),E()}else if(l==="files"&&d){let s=t.value||y||"Download file";o.insertFileLink({href:d,text:s}),E()}}i($,"insertFile");let E=i(()=>e.close(),"close");e.querySelector(".ste-dialog-close").addEventListener("click",E),e.querySelector(".ste-dialog-btn--cancel").addEventListener("click",E),e.addEventListener("click",l=>{l.target===e&&E()}),e.querySelector(".ste-dialog-btn--insert").addEventListener("click",$),e.addEventListener("close",()=>e.remove()),document.body.appendChild(e),e.showModal()}i(re,"createFileDialog");export{U as a,P as b,j as c,w as d,B as e,R as f,ee as g,re as h};
//# sourceMappingURL=chunk-BIB62OO4.js.map
