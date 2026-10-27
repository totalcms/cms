import{a as _}from"./chunk-4ZJGFSDJ.js";import{a as N}from"./chunk-DQ26ZV2Z.js";import{a as L}from"./chunk-PUE4O7W7.js";import"./chunk-EQEHHYOG.js";import"./chunk-K4BYHLEN.js";import"./chunk-LQZAKYF7.js";import"./chunk-JPMZWFIP.js";import"./chunk-SCVYMDL6.js";import"./chunk-SDKAYLQA.js";import"./chunk-SWABZG6D.js";import"./chunk-K3AZG723.js";import"./chunk-2JZ3B2GK.js";import"./chunk-3IJUVK7A.js";import"./chunk-ATNX37W4.js";import"./chunk-2YEZ7CGM.js";import"./chunk-UT2PG3RF.js";import"./chunk-QNHD6WGB.js";import"./chunk-SWYMY4ZU.js";import"./chunk-OQDAZD2N.js";import{p as x}from"./chunk-FHCCFWZU.js";import"./chunk-4BVL6VY4.js";import{B as S,O as D,T,U as z,V as E,W as F,X as P,Y as A,Z as W,q as C}from"./chunk-UEGXJBAQ.js";import{b as v}from"./chunk-6DTRDB76.js";import{a as g}from"./chunk-Y43I3YIM.js";import"./chunk-GY7RRZSA.js";import{a as $}from"./chunk-N5O52Q2M.js";var j=C.packet,M=class{static{$(this,"PacketDB")}constructor(){this.packet=[],this.setAccTitle=z,this.getAccTitle=E,this.setDiagramTitle=A,this.getDiagramTitle=W,this.getAccDescription=P,this.setAccDescription=F}static{g(this,"PacketDB")}getConfig(){let t=x({...j,...S().packet});return t.showBits&&(t.paddingY+=10),t}getPacket(){return this.packet}pushWord(t){t.length>0&&this.packet.push(t)}clear(){T(),this.packet=[]}},G=1e4,H=g((t,e)=>{N(t,e);let a=-1,o=[],s=1,{bitsPerRow:l}=e.getConfig();for(let{start:r,end:i,bits:d,label:h}of t.blocks){if(r!==void 0&&i!==void 0&&i<r)throw new Error(`Packet block ${r} - ${i} is invalid. End must be greater than start.`);if(r??=a+1,r!==a+1)throw new Error(`Packet block ${r} - ${i??r} is not contiguous. It should start from ${a+1}.`);if(d===0)throw new Error(`Packet block ${r} is invalid. Cannot have a zero bit field.`);for(i??=r+(d??1)-1,d??=i-r+1,a=i,v.debug(`Packet block ${r} - ${a} with label ${h}`);o.length<=l+1&&e.getPacket().length<G;){let[c,p]=K({start:r,end:i,bits:d,label:h},s,l);if(o.push(c),c.end+1===s*l&&(e.pushWord(o),o=[],s++),!p)break;({start:r,end:i,bits:d,label:h}=p)}}e.pushWord(o)},"populate"),K=g((t,e,a)=>{if(t.start===void 0)throw new Error("start should have been set during first phase");if(t.end===void 0)throw new Error("end should have been set during first phase");if(t.start>t.end)throw new Error(`Block start ${t.start} is greater than block end ${t.end}.`);if(t.end+1<=e*a)return[t,void 0];let o=e*a-1,s=e*a;return[{start:t.start,end:o,label:t.label,bits:o-t.start},{start:s,end:t.end,label:t.label,bits:t.end-s}]},"getNextFittingBlock"),Y={parser:{yy:void 0},parse:g(async t=>{let e=await L("packet",t),a=Y.parser?.yy;if(!(a instanceof M))throw new Error("parser.parser?.yy was not a PacketDB. This is due to a bug within Mermaid, please report this issue at https://github.com/mermaid-js/mermaid/issues.");v.debug(e),H(e,a)},"parse")},U=g((t,e,a,o)=>{let s=o.db,l=s.getConfig(),{rowHeight:r,paddingY:i,bitWidth:d,bitsPerRow:h}=l,c=s.getPacket(),p=s.getDiagramTitle(),u=r+i,n=u*(c.length+1)-(p?0:r),f=d*h+2,k=_(e);k.attr("viewBox",`0 0 ${f} ${n}`),D(k,n,f,l.useMaxWidth);for(let[y,m]of c.entries())X(k,m,y,l);k.append("text").text(p).attr("x",f/2).attr("y",n-u/2).attr("dominant-baseline","middle").attr("text-anchor","middle").attr("class","packetTitle")},"draw"),X=g((t,e,a,{rowHeight:o,paddingX:s,paddingY:l,bitWidth:r,bitsPerRow:i,showBits:d,bitOrder:h})=>{let c=t.append("g"),p=a*(o+l)+l,u=h==="descending";for(let n of e){let f=n.end-n.start+1,k=n.start%i,m=(u?i-k-f:k)*r+1,b=f*r-s;if(c.append("rect").attr("x",m).attr("y",p).attr("width",b).attr("height",o).attr("class","packetBlock"),c.append("text").attr("x",m+b/2).attr("y",p+o/2).attr("class","packetLabel").attr("dominant-baseline","middle").attr("text-anchor","middle").text(n.label),!d)continue;let[I,O]=u?[n.end,n.start]:[n.start,n.end],w=f===1,B=p-2;c.append("text").attr("x",m+(w?b/2:0)).attr("y",B).attr("class","packetByte start").attr("dominant-baseline","auto").attr("text-anchor",w?"middle":"start").text(I),w||c.append("text").attr("x",m+b).attr("y",B).attr("class","packetByte end").attr("dominant-baseline","auto").attr("text-anchor","end").text(O)}},"drawWord"),q={draw:U},J={byteFontSize:"10px",startByteColor:"black",endByteColor:"black",labelColor:"black",labelFontSize:"12px",titleColor:"black",titleFontSize:"14px",blockStrokeColor:"black",blockStrokeWidth:"1",blockFillColor:"#efefef"},Q=g(({packet:t}={})=>{let e=x(J,t);return`
	.packetByte {
		font-size: ${e.byteFontSize};
	}
	.packetByte.start {
		fill: ${e.startByteColor};
	}
	.packetByte.end {
		fill: ${e.endByteColor};
	}
	.packetLabel {
		fill: ${e.labelColor};
		font-size: ${e.labelFontSize};
	}
	.packetTitle {
		fill: ${e.titleColor};
		font-size: ${e.titleFontSize};
	}
	.packetBlock {
		stroke: ${e.blockStrokeColor};
		stroke-width: ${e.blockStrokeWidth};
		fill: ${e.blockFillColor};
	}
	`},"styles"),ot={parser:Y,get db(){return new M},renderer:q,styles:Q};export{ot as diagram};
//# sourceMappingURL=diagram-I5XIIV2D-FVBS23H6.js.map
