import{a as _}from"./chunk-NO6K35DI.js";import{a as D}from"./chunk-AF4KOFE3.js";import"./chunk-DTZL4WZR.js";import"./chunk-XUOTW2GH.js";import{a as F}from"./chunk-WKZG2TWQ.js";import"./chunk-DHQC2DEC.js";import"./chunk-7WQY3RNM.js";import"./chunk-HRM4MWVN.js";import"./chunk-7K4S4PFO.js";import"./chunk-NGWTPS6A.js";import"./chunk-RNPNXPLM.js";import"./chunk-WM6NC2E2.js";import"./chunk-GEV52DIX.js";import"./chunk-OC53G7BN.js";import{o as C}from"./chunk-I7VLYHZC.js";import"./chunk-RQPVFJNQ.js";import{A as w,N as L,S as T,T as S,U as O,V as k,W as R,X as I,Y as E,o as M,q as A}from"./chunk-6KJDGQLC.js";import{b as o,d as b}from"./chunk-2UWKIQCG.js";import"./chunk-SN3VZCFU.js";import{a as $}from"./chunk-B4JUHIHW.js";var x={showLegend:!0,ticks:5,max:null,min:0,graticule:"circle"},z={axes:[],curves:[],options:x},g=structuredClone(z),N=A.radar,U=o(()=>C({...N,...w().radar}),"getConfig"),G=o(()=>g.axes,"getAxes"),X=o(()=>g.curves,"getCurves"),Y=o(()=>g.options,"getOptions"),Z=o(a=>{g.axes=a.map(t=>({name:t.name,label:t.label??t.name}))},"setAxes"),q=o(a=>{g.curves=a.map(t=>({name:t.name,label:t.label??t.name,entries:J(t.entries)}))},"setCurves"),J=o(a=>{if(a[0].axis==null)return a.map(e=>e.value);let t=G();if(t.length===0)throw new Error("Axes must be populated before curves for reference entries");return t.map(e=>{let r=a.find(n=>n.axis?.$refText===e.name);if(r===void 0)throw new Error("Missing entry for axis "+e.label);return r.value})},"computeCurveEntries"),K=o(a=>{let t=a.reduce((e,r)=>(e[r.name]=r,e),{});g.options={showLegend:t.showLegend?.value??x.showLegend,ticks:t.ticks?.value??x.ticks,max:t.max?.value??x.max,min:t.min?.value??x.min,graticule:t.graticule?.value??x.graticule}},"setOptions"),Q=o(()=>{T(),g=structuredClone(z)},"clear"),f={getAxes:G,getCurves:X,getOptions:Y,setAxes:Z,setCurves:q,setOptions:K,getConfig:U,clear:Q,setAccTitle:S,getAccTitle:O,setDiagramTitle:I,getDiagramTitle:E,getAccDescription:R,setAccDescription:k},tt=o(a=>{_(a,f);let{axes:t,curves:e,options:r}=a;f.setAxes(t),f.setCurves(e),f.setOptions(r)},"populate"),et={parse:o(async a=>{let t=await D("radar",a);b.debug(t),tt(t)},"parse")},at=o((a,t,e,r)=>{let n=r.db,i=n.getAxes(),l=n.getCurves(),s=n.getOptions(),c=n.getConfig(),d=n.getDiagramTitle(),p=F(t),u=rt(p,c),m=s.max??Math.max(...l.map(y=>Math.max(...y.entries))),h=s.min,v=Math.min(c.width,c.height)/2;nt(u,i,v,s.ticks,s.graticule),st(u,i,v,c),P(u,i,l,h,m,s.graticule,c),V(u,l,s.showLegend,c),u.append("text").attr("class","radarTitle").text(d).attr("x",0).attr("y",-c.height/2-c.marginTop)},"draw"),rt=o((a,t)=>{let e=t.width+t.marginLeft+t.marginRight,r=t.height+t.marginTop+t.marginBottom,n={x:t.marginLeft+t.width/2,y:t.marginTop+t.height/2};return L(a,r,e,t.useMaxWidth??!0),a.attr("viewBox",`0 0 ${e} ${r}`),a.append("g").attr("transform",`translate(${n.x}, ${n.y})`)},"drawFrame"),nt=o((a,t,e,r,n)=>{if(n==="circle")for(let i=0;i<r;i++){let l=e*(i+1)/r;a.append("circle").attr("r",l).attr("class","radarGraticule")}else if(n==="polygon"){let i=t.length;for(let l=0;l<r;l++){let s=e*(l+1)/r,c=t.map((d,p)=>{let u=2*p*Math.PI/i-Math.PI/2,m=s*Math.cos(u),h=s*Math.sin(u);return`${m},${h}`}).join(" ");a.append("polygon").attr("points",c).attr("class","radarGraticule")}}},"drawGraticule"),st=o((a,t,e,r)=>{let n=t.length;for(let i=0;i<n;i++){let l=t[i].label,s=2*i*Math.PI/n-Math.PI/2;a.append("line").attr("x1",0).attr("y1",0).attr("x2",e*r.axisScaleFactor*Math.cos(s)).attr("y2",e*r.axisScaleFactor*Math.sin(s)).attr("class","radarAxisLine"),a.append("text").text(l).attr("x",e*r.axisLabelFactor*Math.cos(s)).attr("y",e*r.axisLabelFactor*Math.sin(s)).attr("class","radarAxisLabel")}},"drawAxes");function P(a,t,e,r,n,i,l){let s=t.length,c=Math.min(l.width,l.height)/2;e.forEach((d,p)=>{if(d.entries.length!==s)return;let u=d.entries.map((m,h)=>{let v=2*Math.PI*h/s-Math.PI/2,y=W(m,r,n,c),H=y*Math.cos(v),j=y*Math.sin(v);return{x:H,y:j}});i==="circle"?a.append("path").attr("d",B(u,l.curveTension)).attr("class",`radarCurve-${p}`):i==="polygon"&&a.append("polygon").attr("points",u.map(m=>`${m.x},${m.y}`).join(" ")).attr("class",`radarCurve-${p}`)})}$(P,"drawCurves");o(P,"drawCurves");function W(a,t,e,r){let n=Math.min(Math.max(a,t),e);return r*(n-t)/(e-t)}$(W,"relativeRadius");o(W,"relativeRadius");function B(a,t){let e=a.length,r=`M${a[0].x},${a[0].y}`;for(let n=0;n<e;n++){let i=a[(n-1+e)%e],l=a[n],s=a[(n+1)%e],c=a[(n+2)%e],d={x:l.x+(s.x-i.x)*t,y:l.y+(s.y-i.y)*t},p={x:s.x-(c.x-l.x)*t,y:s.y-(c.y-l.y)*t};r+=` C${d.x},${d.y} ${p.x},${p.y} ${s.x},${s.y}`}return`${r} Z`}$(B,"closedRoundCurve");o(B,"closedRoundCurve");function V(a,t,e,r){if(!e)return;let n=(r.width/2+r.marginRight)*3/4,i=-(r.height/2+r.marginTop)*3/4,l=20;t.forEach((s,c)=>{let d=a.append("g").attr("transform",`translate(${n}, ${i+c*l})`);d.append("rect").attr("width",12).attr("height",12).attr("class",`radarLegendBox-${c}`),d.append("text").attr("x",16).attr("y",0).attr("class","radarLegendText").text(s.label)})}$(V,"drawLegend");o(V,"drawLegend");var ot={draw:at},it=o((a,t)=>{let e="";for(let r=0;r<a.THEME_COLOR_LIMIT;r++){let n=a[`cScale${r}`];e+=`
		.radarCurve-${r} {
			color: ${n};
			fill: ${n};
			fill-opacity: ${t.curveOpacity};
			stroke: ${n};
			stroke-width: ${t.curveStrokeWidth};
		}
		.radarLegendBox-${r} {
			fill: ${n};
			fill-opacity: ${t.curveOpacity};
			stroke: ${n};
		}
		`}return e},"genIndexStyles"),lt=o(a=>{let t=M(),e=w(),r=C(t,e.themeVariables),n=C(r.radar,a);return{themeVariables:r,radarOptions:n}},"buildRadarStyleOptions"),ct=o(({radar:a}={})=>{let{themeVariables:t,radarOptions:e}=lt(a);return`
	.radarTitle {
		font-size: ${t.fontSize};
		color: ${t.titleColor};
		dominant-baseline: hanging;
		text-anchor: middle;
	}
	.radarAxisLine {
		stroke: ${e.axisColor};
		stroke-width: ${e.axisStrokeWidth};
	}
	.radarAxisLabel {
		dominant-baseline: middle;
		text-anchor: middle;
		font-size: ${e.axisLabelFontSize}px;
		color: ${e.axisColor};
	}
	.radarGraticule {
		fill: ${e.graticuleColor};
		fill-opacity: ${e.graticuleOpacity};
		stroke: ${e.graticuleColor};
		stroke-width: ${e.graticuleStrokeWidth};
	}
	.radarLegendText {
		text-anchor: start;
		font-size: ${e.legendFontSize}px;
		dominant-baseline: hanging;
	}
	${it(t,e)}
	`},"styles"),ht={parser:et,db:f,renderer:ot,styles:ct};export{ht as diagram};
//# sourceMappingURL=diagram-2AECGRRQ-IRXKOS3X.js.map
