import{a as xe,b as we,f as _e,i as At,j as Lt,k as Ft,l as Ot,m as Wt,n as De,o as Se,p as Ce,q as Me,r as Ee,s as Ie,t as Ye,u as Vt,v as Pt,y as $e,z as Ae}from"./chunk-MKDAZBLV.js";import{q as Le}from"./chunk-J5WE3UHW.js";import{a as oi}from"./chunk-3OKMSUOS.js";import{N as he,O as me,T as ke,U as ye,V as ge,W as pe,X as ve,Y as Te,Z as be,_ as ct}from"./chunk-6DWN7XYP.js";import{a as fe,b as tt,d as Tt}from"./chunk-7A2NCBUN.js";import{a as l}from"./chunk-M66D53DD.js";import"./chunk-GY7RRZSA.js";import{a as _,c as _t,d as ot}from"./chunk-N5O52Q2M.js";var Pe=_t((Nt,Ht)=>{(function(t,e){typeof Nt=="object"&&typeof Ht<"u"?Ht.exports=e():typeof define=="function"&&define.amd?define(e):(t=typeof globalThis<"u"?globalThis:t||self).dayjs_plugin_isoWeek=e()})(Nt,(function(){"use strict";var t="day";return function(e,r,n){var s=_(function(S){return S.add(4-S.isoWeekday(),t)},"a"),d=r.prototype;d.isoWeekYear=function(){return s(this).year()},d.isoWeek=function(S){if(!this.$utils().u(S))return this.add(7*(S-this.isoWeek()),t);var w,P,Y,N,C=s(this),M=(w=this.isoWeekYear(),P=this.$u,Y=(P?n.utc:n)().year(w).startOf("year"),N=4-Y.isoWeekday(),Y.isoWeekday()>4&&(N+=7),Y.add(N,t));return C.diff(M,"week")+1},d.isoWeekday=function(S){return this.$utils().u(S)?this.day()||7:this.day(this.day()%7?S:S-7)};var y=d.startOf;d.startOf=function(S,w){var P=this.$utils(),Y=!!P.u(w)||w;return P.p(S)==="isoweek"?Y?this.date(this.date()-(this.isoWeekday()-1)).startOf("day"):this.date(this.date()-1-(this.isoWeekday()-1)+7).endOf("day"):y.bind(this)(S,w)}}}))});var ze=_t((Bt,jt)=>{(function(t,e){typeof Bt=="object"&&typeof jt<"u"?jt.exports=e():typeof define=="function"&&define.amd?define(e):(t=typeof globalThis<"u"?globalThis:t||self).dayjs_plugin_customParseFormat=e()})(Bt,(function(){"use strict";var t={LTS:"h:mm:ss A",LT:"h:mm A",L:"MM/DD/YYYY",LL:"MMMM D, YYYY",LLL:"MMMM D, YYYY h:mm A",LLLL:"dddd, MMMM D, YYYY h:mm A"},e=/(\[[^[]*\])|([-_:/.,()\s]+)|(A|a|Q|YYYY|YY?|ww?|MM?M?M?|Do|DD?|hh?|HH?|mm?|ss?|S{1,3}|z|ZZ?)/g,r=/\d/,n=/\d\d/,s=/\d\d?/,d=/\d*[^-_:/,()\s\d]+/,y={},S=_(function(b){return(b=+b)+(b>68?1900:2e3)},"a"),w=_(function(b){return function(D){this[b]=+D}},"f"),P=[/[+-]\d\d:?(\d\d)?|Z/,function(b){(this.zone||(this.zone={})).offset=(function(D){if(!D||D==="Z")return 0;var x=D.match(/([+-]|\d\d)/g),W=60*x[1]+(+x[2]||0);return W===0?0:x[0]==="+"?-W:W})(b)}],Y=_(function(b){var D=y[b];return D&&(D.indexOf?D:D.s.concat(D.f))},"u"),N=_(function(b,D){var x,W=y.meridiem;if(W){for(var j=1;j<=24;j+=1)if(b.indexOf(W(j,0,D))>-1){x=j>12;break}}else x=b===(D?"pm":"PM");return x},"d"),C={A:[d,function(b){this.afternoon=N(b,!1)}],a:[d,function(b){this.afternoon=N(b,!0)}],Q:[r,function(b){this.month=3*(b-1)+1}],S:[r,function(b){this.milliseconds=100*+b}],SS:[n,function(b){this.milliseconds=10*+b}],SSS:[/\d{3}/,function(b){this.milliseconds=+b}],s:[s,w("seconds")],ss:[s,w("seconds")],m:[s,w("minutes")],mm:[s,w("minutes")],H:[s,w("hours")],h:[s,w("hours")],HH:[s,w("hours")],hh:[s,w("hours")],D:[s,w("day")],DD:[n,w("day")],Do:[d,function(b){var D=y.ordinal,x=b.match(/\d+/);if(this.day=x[0],D)for(var W=1;W<=31;W+=1)D(W).replace(/\[|\]/g,"")===b&&(this.day=W)}],w:[s,w("week")],ww:[n,w("week")],M:[s,w("month")],MM:[n,w("month")],MMM:[d,function(b){var D=Y("months"),x=(Y("monthsShort")||D.map((function(W){return W.slice(0,3)}))).indexOf(b)+1;if(x<1)throw new Error;this.month=x%12||x}],MMMM:[d,function(b){var D=Y("months").indexOf(b)+1;if(D<1)throw new Error;this.month=D%12||D}],Y:[/[+-]?\d+/,w("year")],YY:[n,function(b){this.year=S(b)}],YYYY:[/\d{4}/,w("year")],Z:P,ZZ:P};function M(b){var D,x;D=b,x=y&&y.formats;for(var W=(b=D.replace(/(\[[^\]]+])|(LTS?|l{1,4}|L{1,4})/g,(function(V,O,m){var v=m&&m.toUpperCase();return O||x[m]||t[m]||x[v].replace(/(\[[^\]]+])|(MMMM|MM|DD|dddd)/g,(function(g,T,a){return T||a.slice(1)}))}))).match(e),j=W.length,G=0;G<j;G+=1){var F=W[G],p=C[F],f=p&&p[0],E=p&&p[1];W[G]=E?{regex:f,parser:E}:F.replace(/^\[|\]$/g,"")}return function(V){for(var O={},m=0,v=0;m<j;m+=1){var g=W[m];if(typeof g=="string")v+=g.length;else{var T=g.regex,a=g.parser,k=V.slice(v),h=T.exec(k)[0];a.call(O,h),V=V.replace(h,"")}}return(function(u){var $=u.afternoon;if($!==void 0){var i=u.hours;$?i<12&&(u.hours+=12):i===12&&(u.hours=0),delete u.afternoon}})(O),O}}return _(M,"l"),function(b,D,x){x.p.customParseFormat=!0,b&&b.parseTwoDigitYear&&(S=b.parseTwoDigitYear);var W=D.prototype,j=W.parse;W.parse=function(G){var F=G.date,p=G.utc,f=G.args;this.$u=p;var E=f[1];if(typeof E=="string"){var V=f[2]===!0,O=f[3]===!0,m=V||O,v=f[2];O&&(v=f[2]),y=this.$locale(),!V&&v&&(y=x.Ls[v]),this.$d=(function(k,h,u,$){try{if(["x","X"].indexOf(h)>-1)return new Date((h==="X"?1e3:1)*k);var i=M(h)(k),A=i.year,c=i.month,X=i.day,o=i.hours,I=i.minutes,L=i.seconds,H=i.milliseconds,B=i.zone,z=i.week,R=new Date,it=X||(A||c?1:R.getDate()),nt=A||R.getFullYear(),ut=0;A&&!c||(ut=c>0?c-1:R.getMonth());var dt,ft=o||0,U=I||0,at=L||0,J=H||0;return B?new Date(Date.UTC(nt,ut,it,ft,U,at,J+60*B.offset*1e3)):u?new Date(Date.UTC(nt,ut,it,ft,U,at,J)):(dt=new Date(nt,ut,it,ft,U,at,J),z&&(dt=$(dt).week(z).toDate()),dt)}catch{return new Date("")}})(F,E,p,x),this.init(),v&&v!==!0&&(this.$L=this.locale(v).$L),m&&F!=this.format(E)&&(this.$d=new Date("")),y={}}else if(E instanceof Array)for(var g=E.length,T=1;T<=g;T+=1){f[1]=E[T-1];var a=x.apply(this,f);if(a.isValid()){this.$d=a.$d,this.$L=a.$L,this.init();break}T===g&&(this.$d=new Date(""))}else j.call(this,G)}}}))});var Re=_t((Gt,Xt)=>{(function(t,e){typeof Gt=="object"&&typeof Xt<"u"?Xt.exports=e():typeof define=="function"&&define.amd?define(e):(t=typeof globalThis<"u"?globalThis:t||self).dayjs_plugin_advancedFormat=e()})(Gt,(function(){"use strict";return function(t,e){var r=e.prototype,n=r.format;r.format=function(s){var d=this,y=this.$locale();if(!this.isValid())return n.bind(this)(s);var S=this.$utils(),w=(s||"YYYY-MM-DDTHH:mm:ssZ").replace(/\[([^\]]+)]|Q|wo|ww|w|WW|W|zzz|z|gggg|GGGG|Do|X|x|k{1,2}|S/g,(function(P){switch(P){case"Q":return Math.ceil((d.$M+1)/3);case"Do":return y.ordinal(d.$D);case"gggg":return d.weekYear();case"GGGG":return d.isoWeekYear();case"wo":return y.ordinal(d.week(),"W");case"w":case"ww":return S.s(d.week(),P==="w"?1:2,"0");case"W":case"WW":return S.s(d.isoWeek(),P==="W"?1:2,"0");case"k":case"kk":return S.s(String(d.$H===0?24:d.$H),P==="k"?1:2,"0");case"X":return Math.floor(d.$d.getTime()/1e3);case"x":return d.$d.getTime();case"z":return"["+d.offsetName()+"]";case"zzz":return"["+d.offsetName("long")+"]";default:return P}}));return n.bind(this)(w)}}}))});var Ne=_t((Ut,qt)=>{(function(t,e){typeof Ut=="object"&&typeof qt<"u"?qt.exports=e():typeof define=="function"&&define.amd?define(e):(t=typeof globalThis<"u"?globalThis:t||self).dayjs_plugin_duration=e()})(Ut,(function(){"use strict";var t,e,r=1e3,n=6e4,s=36e5,d=864e5,y=31536e6,S=2628e6,w=/^(-|\+)?P(?:([-+]?[0-9,.]*)Y)?(?:([-+]?[0-9,.]*)M)?(?:([-+]?[0-9,.]*)W)?(?:([-+]?[0-9,.]*)D)?(?:T(?:([-+]?[0-9,.]*)H)?(?:([-+]?[0-9,.]*)M)?(?:([-+]?[0-9,.]*)S)?)?$/,P=/\[([^\]]+)]|YYYY|YY|Y|M{1,2}|D{1,2}|H{1,2}|m{1,2}|s{1,2}|SSS/g,Y={years:y,months:S,days:d,hours:s,minutes:n,seconds:r,milliseconds:1,weeks:6048e5},N=_(function(F){return F instanceof j},"c"),C=_(function(F,p,f){return new j(F,f,p.$l)},"f"),M=_(function(F){return e.p(F)+"s"},"m"),b=_(function(F){return F<0},"l"),D=_(function(F){return b(F)?Math.ceil(F):Math.floor(F)},"$"),x=_(function(F){return Math.abs(F)},"y"),W=_(function(F,p){return F?b(F)?{negative:!0,format:""+x(F)+p}:{negative:!1,format:""+F+p}:{negative:!1,format:""}},"v"),j=(function(){function F(f,E,V){var O=this;if(this.$d={},this.$l=V,f===void 0&&(this.$ms=0,this.parseFromMilliseconds()),E)return C(f*Y[M(E)],this);if(typeof f=="number")return this.$ms=f,this.parseFromMilliseconds(),this;if(typeof f=="object")return Object.keys(f).forEach((function(g){O.$d[M(g)]=f[g]})),this.calMilliseconds(),this;if(typeof f=="string"){var m=f.match(w);if(m){var v=m.slice(2).map((function(g){return g!=null?Number(g):0}));return this.$d.years=v[0],this.$d.months=v[1],this.$d.weeks=v[2],this.$d.days=v[3],this.$d.hours=v[4],this.$d.minutes=v[5],this.$d.seconds=v[6],this.calMilliseconds(),this}}return this}_(F,"l");var p=F.prototype;return p.calMilliseconds=function(){var f=this;this.$ms=Object.keys(this.$d).reduce((function(E,V){return E+(f.$d[V]||0)*Y[V]}),0)},p.parseFromMilliseconds=function(){var f=this.$ms;this.$d.years=D(f/y),f%=y,this.$d.months=D(f/S),f%=S,this.$d.days=D(f/d),f%=d,this.$d.hours=D(f/s),f%=s,this.$d.minutes=D(f/n),f%=n,this.$d.seconds=D(f/r),f%=r,this.$d.milliseconds=f},p.toISOString=function(){var f=W(this.$d.years,"Y"),E=W(this.$d.months,"M"),V=+this.$d.days||0;this.$d.weeks&&(V+=7*this.$d.weeks);var O=W(V,"D"),m=W(this.$d.hours,"H"),v=W(this.$d.minutes,"M"),g=this.$d.seconds||0;this.$d.milliseconds&&(g+=this.$d.milliseconds/1e3,g=Math.round(1e3*g)/1e3);var T=W(g,"S"),a=f.negative||E.negative||O.negative||m.negative||v.negative||T.negative,k=m.format||v.format||T.format?"T":"",h=(a?"-":"")+"P"+f.format+E.format+O.format+k+m.format+v.format+T.format;return h==="P"||h==="-P"?"P0D":h},p.toJSON=function(){return this.toISOString()},p.format=function(f){var E=f||"YYYY-MM-DDTHH:mm:ss",V={Y:this.$d.years,YY:e.s(this.$d.years,2,"0"),YYYY:e.s(this.$d.years,4,"0"),M:this.$d.months,MM:e.s(this.$d.months,2,"0"),D:this.$d.days,DD:e.s(this.$d.days,2,"0"),H:this.$d.hours,HH:e.s(this.$d.hours,2,"0"),m:this.$d.minutes,mm:e.s(this.$d.minutes,2,"0"),s:this.$d.seconds,ss:e.s(this.$d.seconds,2,"0"),SSS:e.s(this.$d.milliseconds,3,"0")};return E.replace(P,(function(O,m){return m||String(V[O])}))},p.as=function(f){return this.$ms/Y[M(f)]},p.get=function(f){var E=this.$ms,V=M(f);return V==="milliseconds"?E%=1e3:E=V==="weeks"?D(E/Y[V]):this.$d[V],E||0},p.add=function(f,E,V){var O;return O=E?f*Y[M(E)]:N(f)?f.$ms:C(f,this).$ms,C(this.$ms+O*(V?-1:1),this)},p.subtract=function(f,E){return this.add(f,E,!0)},p.locale=function(f){var E=this.clone();return E.$l=f,E},p.clone=function(){return C(this.$ms,this)},p.humanize=function(f){return t().add(this.$ms,"ms").locale(this.$l).fromNow(!f)},p.valueOf=function(){return this.asMilliseconds()},p.milliseconds=function(){return this.get("milliseconds")},p.asMilliseconds=function(){return this.as("milliseconds")},p.seconds=function(){return this.get("seconds")},p.asSeconds=function(){return this.as("seconds")},p.minutes=function(){return this.get("minutes")},p.asMinutes=function(){return this.as("minutes")},p.hours=function(){return this.get("hours")},p.asHours=function(){return this.as("hours")},p.days=function(){return this.get("days")},p.asDays=function(){return this.as("days")},p.weeks=function(){return this.get("weeks")},p.asWeeks=function(){return this.as("weeks")},p.months=function(){return this.get("months")},p.asMonths=function(){return this.as("months")},p.years=function(){return this.get("years")},p.asYears=function(){return this.as("years")},F})(),G=_(function(F,p,f){return F.add(p.years()*f,"y").add(p.months()*f,"M").add(p.days()*f,"d").add(p.hours()*f,"h").add(p.minutes()*f,"m").add(p.seconds()*f,"s").add(p.milliseconds()*f,"ms")},"p");return function(F,p,f){t=f,e=f().$utils(),f.duration=function(O,m){var v=f.locale();return C(O,{$l:v},m)},f.isDuration=N;var E=p.prototype.add,V=p.prototype.subtract;p.prototype.add=function(O,m){return N(O)?G(this,O,1):E.bind(this)(O,m)},p.prototype.subtract=function(O,m){return N(O)?G(this,O,-1):V.bind(this)(O,m)}}}))});function ci(t){return t}_(ci,"identity");var St=1,zt=2,Rt=3,Dt=4,Fe=1e-6;function li(t){return"translate("+t+",0)"}_(li,"translateX");function ui(t){return"translate(0,"+t+")"}_(ui,"translateY");function di(t){return e=>+t(e)}_(di,"number");function fi(t,e){return e=Math.max(0,t.bandwidth()-e*2)/2,t.round()&&(e=Math.round(e)),r=>+t(r)+e}_(fi,"center");function hi(){return!this.__axis}_(hi,"entering");function Oe(t,e){var r=[],n=null,s=null,d=6,y=6,S=3,w=typeof window<"u"&&window.devicePixelRatio>1?0:.5,P=t===St||t===Dt?-1:1,Y=t===Dt||t===zt?"x":"y",N=t===St||t===Rt?li:ui;function C(M){var b=n??(e.ticks?e.ticks.apply(e,r):e.domain()),D=s??(e.tickFormat?e.tickFormat.apply(e,r):ci),x=Math.max(d,0)+S,W=e.range(),j=+W[0]+w,G=+W[W.length-1]+w,F=(e.bandwidth?fi:di)(e.copy(),w),p=M.selection?M.selection():M,f=p.selectAll(".domain").data([null]),E=p.selectAll(".tick").data(b,e).order(),V=E.exit(),O=E.enter().append("g").attr("class","tick"),m=E.select("line"),v=E.select("text");f=f.merge(f.enter().insert("path",".tick").attr("class","domain").attr("stroke","currentColor")),E=E.merge(O),m=m.merge(O.append("line").attr("stroke","currentColor").attr(Y+"2",P*d)),v=v.merge(O.append("text").attr("fill","currentColor").attr(Y,P*x).attr("dy",t===St?"0em":t===Rt?"0.71em":"0.32em")),M!==p&&(f=f.transition(M),E=E.transition(M),m=m.transition(M),v=v.transition(M),V=V.transition(M).attr("opacity",Fe).attr("transform",function(g){return isFinite(g=F(g))?N(g+w):this.getAttribute("transform")}),O.attr("opacity",Fe).attr("transform",function(g){var T=this.parentNode.__axis;return N((T&&isFinite(T=T(g))?T:F(g))+w)})),V.remove(),f.attr("d",t===Dt||t===zt?y?"M"+P*y+","+j+"H"+w+"V"+G+"H"+P*y:"M"+w+","+j+"V"+G:y?"M"+j+","+P*y+"V"+w+"H"+G+"V"+P*y:"M"+j+","+w+"H"+G),E.attr("opacity",1).attr("transform",function(g){return N(F(g)+w)}),m.attr(Y+"2",P*d),v.attr(Y,P*x).text(D),p.filter(hi).attr("fill","none").attr("font-size",10).attr("font-family","sans-serif").attr("text-anchor",t===zt?"start":t===Dt?"end":"middle"),p.each(function(){this.__axis=F})}return _(C,"axis"),C.scale=function(M){return arguments.length?(e=M,C):e},C.ticks=function(){return r=Array.from(arguments),C},C.tickArguments=function(M){return arguments.length?(r=M==null?[]:Array.from(M),C):r.slice()},C.tickValues=function(M){return arguments.length?(n=M==null?null:Array.from(M),C):n&&n.slice()},C.tickFormat=function(M){return arguments.length?(s=M,C):s},C.tickSize=function(M){return arguments.length?(d=y=+M,C):d},C.tickSizeInner=function(M){return arguments.length?(d=+M,C):d},C.tickSizeOuter=function(M){return arguments.length?(y=+M,C):y},C.tickPadding=function(M){return arguments.length?(S=+M,C):S},C.offset=function(M){return arguments.length?(w=+M,C):w},C}_(Oe,"axis");function We(t){return Oe(St,t)}_(We,"axisTop");function Ve(t){return Oe(Rt,t)}_(Ve,"axisBottom");var Ge=ot(oi(),1),K=ot(fe(),1),Xe=ot(Pe(),1),Ue=ot(ze(),1),qe=ot(Re(),1),kt=ot(fe(),1),ai=ot(Ne(),1);var Qt=(function(){var t=l(function(T,a,k,h){for(k=k||{},h=T.length;h--;k[T[h]]=a);return k},"o"),e=[6,8,10,12,13,14,15,16,17,18,20,21,22,23,24,25,26,27,28,29,30,31,33,35,36,38,40],r=[1,26],n=[1,27],s=[1,28],d=[1,29],y=[1,30],S=[1,31],w=[1,32],P=[1,33],Y=[1,34],N=[1,9],C=[1,10],M=[1,11],b=[1,12],D=[1,13],x=[1,14],W=[1,15],j=[1,16],G=[1,19],F=[1,20],p=[1,21],f=[1,22],E=[1,23],V=[1,25],O=[1,35],m={trace:l(_(function(){},"trace"),"trace"),yy:{},symbols_:{error:2,start:3,gantt:4,document:5,EOF:6,line:7,SPACE:8,statement:9,NL:10,weekday:11,weekday_monday:12,weekday_tuesday:13,weekday_wednesday:14,weekday_thursday:15,weekday_friday:16,weekday_saturday:17,weekday_sunday:18,weekend:19,weekend_friday:20,weekend_saturday:21,dateFormat:22,inclusiveEndDates:23,topAxis:24,axisFormat:25,tickInterval:26,excludes:27,includes:28,todayMarker:29,title:30,acc_title:31,acc_title_value:32,acc_descr:33,acc_descr_value:34,acc_descr_multiline_value:35,section:36,clickStatement:37,taskTxt:38,taskData:39,click:40,callbackname:41,callbackargs:42,href:43,clickStatementDebug:44,$accept:0,$end:1},terminals_:{2:"error",4:"gantt",6:"EOF",8:"SPACE",10:"NL",12:"weekday_monday",13:"weekday_tuesday",14:"weekday_wednesday",15:"weekday_thursday",16:"weekday_friday",17:"weekday_saturday",18:"weekday_sunday",20:"weekend_friday",21:"weekend_saturday",22:"dateFormat",23:"inclusiveEndDates",24:"topAxis",25:"axisFormat",26:"tickInterval",27:"excludes",28:"includes",29:"todayMarker",30:"title",31:"acc_title",32:"acc_title_value",33:"acc_descr",34:"acc_descr_value",35:"acc_descr_multiline_value",36:"section",38:"taskTxt",39:"taskData",40:"click",41:"callbackname",42:"callbackargs",43:"href"},productions_:[0,[3,3],[5,0],[5,2],[7,2],[7,1],[7,1],[7,1],[11,1],[11,1],[11,1],[11,1],[11,1],[11,1],[11,1],[19,1],[19,1],[9,1],[9,1],[9,1],[9,1],[9,1],[9,1],[9,1],[9,1],[9,1],[9,1],[9,1],[9,2],[9,2],[9,1],[9,1],[9,1],[9,2],[37,2],[37,3],[37,3],[37,4],[37,3],[37,4],[37,2],[44,2],[44,3],[44,3],[44,4],[44,3],[44,4],[44,2]],performAction:l(_(function(a,k,h,u,$,i,A){var c=i.length-1;switch($){case 1:return i[c-1];case 2:this.$=[];break;case 3:i[c-1].push(i[c]),this.$=i[c-1];break;case 4:case 5:this.$=i[c];break;case 6:case 7:this.$=[];break;case 8:u.setWeekday("monday");break;case 9:u.setWeekday("tuesday");break;case 10:u.setWeekday("wednesday");break;case 11:u.setWeekday("thursday");break;case 12:u.setWeekday("friday");break;case 13:u.setWeekday("saturday");break;case 14:u.setWeekday("sunday");break;case 15:u.setWeekend("friday");break;case 16:u.setWeekend("saturday");break;case 17:u.setDateFormat(i[c].substr(11)),this.$=i[c].substr(11);break;case 18:u.enableInclusiveEndDates(),this.$=i[c].substr(18);break;case 19:u.TopAxis(),this.$=i[c].substr(8);break;case 20:u.setAxisFormat(i[c].substr(11)),this.$=i[c].substr(11);break;case 21:u.setTickInterval(i[c].substr(13)),this.$=i[c].substr(13);break;case 22:u.setExcludes(i[c].substr(9)),this.$=i[c].substr(9);break;case 23:u.setIncludes(i[c].substr(9)),this.$=i[c].substr(9);break;case 24:u.setTodayMarker(i[c].substr(12)),this.$=i[c].substr(12);break;case 27:u.setDiagramTitle(i[c].substr(6)),this.$=i[c].substr(6);break;case 28:this.$=i[c].trim(),u.setAccTitle(this.$);break;case 29:case 30:this.$=i[c].trim(),u.setAccDescription(this.$);break;case 31:u.addSection(i[c].substr(8)),this.$=i[c].substr(8);break;case 33:u.addTask(i[c-1],i[c]),this.$="task";break;case 34:this.$=i[c-1],u.setClickEvent(i[c-1],i[c],null);break;case 35:this.$=i[c-2],u.setClickEvent(i[c-2],i[c-1],i[c]);break;case 36:this.$=i[c-2],u.setClickEvent(i[c-2],i[c-1],null),u.setLink(i[c-2],i[c]);break;case 37:this.$=i[c-3],u.setClickEvent(i[c-3],i[c-2],i[c-1]),u.setLink(i[c-3],i[c]);break;case 38:this.$=i[c-2],u.setClickEvent(i[c-2],i[c],null),u.setLink(i[c-2],i[c-1]);break;case 39:this.$=i[c-3],u.setClickEvent(i[c-3],i[c-1],i[c]),u.setLink(i[c-3],i[c-2]);break;case 40:this.$=i[c-1],u.setLink(i[c-1],i[c]);break;case 41:case 47:this.$=i[c-1]+" "+i[c];break;case 42:case 43:case 45:this.$=i[c-2]+" "+i[c-1]+" "+i[c];break;case 44:case 46:this.$=i[c-3]+" "+i[c-2]+" "+i[c-1]+" "+i[c];break}},"anonymous"),"anonymous"),table:[{3:1,4:[1,2]},{1:[3]},t(e,[2,2],{5:3}),{6:[1,4],7:5,8:[1,6],9:7,10:[1,8],11:17,12:r,13:n,14:s,15:d,16:y,17:S,18:w,19:18,20:P,21:Y,22:N,23:C,24:M,25:b,26:D,27:x,28:W,29:j,30:G,31:F,33:p,35:f,36:E,37:24,38:V,40:O},t(e,[2,7],{1:[2,1]}),t(e,[2,3]),{9:36,11:17,12:r,13:n,14:s,15:d,16:y,17:S,18:w,19:18,20:P,21:Y,22:N,23:C,24:M,25:b,26:D,27:x,28:W,29:j,30:G,31:F,33:p,35:f,36:E,37:24,38:V,40:O},t(e,[2,5]),t(e,[2,6]),t(e,[2,17]),t(e,[2,18]),t(e,[2,19]),t(e,[2,20]),t(e,[2,21]),t(e,[2,22]),t(e,[2,23]),t(e,[2,24]),t(e,[2,25]),t(e,[2,26]),t(e,[2,27]),{32:[1,37]},{34:[1,38]},t(e,[2,30]),t(e,[2,31]),t(e,[2,32]),{39:[1,39]},t(e,[2,8]),t(e,[2,9]),t(e,[2,10]),t(e,[2,11]),t(e,[2,12]),t(e,[2,13]),t(e,[2,14]),t(e,[2,15]),t(e,[2,16]),{41:[1,40],43:[1,41]},t(e,[2,4]),t(e,[2,28]),t(e,[2,29]),t(e,[2,33]),t(e,[2,34],{42:[1,42],43:[1,43]}),t(e,[2,40],{41:[1,44]}),t(e,[2,35],{43:[1,45]}),t(e,[2,36]),t(e,[2,38],{42:[1,46]}),t(e,[2,37]),t(e,[2,39])],defaultActions:{},parseError:l(_(function(a,k){if(k.recoverable)this.trace(a);else{var h=new Error(a);throw h.hash=k,h}},"parseError"),"parseError"),parse:l(_(function(a){var k=this,h=[0],u=[],$=[null],i=[],A=this.table,c="",X=0,o=0,I=0,L=2,H=1,B=i.slice.call(arguments,1),z=Object.create(this.lexer),R={yy:{}};for(var it in this.yy)Object.prototype.hasOwnProperty.call(this.yy,it)&&(R.yy[it]=this.yy[it]);z.setInput(a,R.yy),R.yy.lexer=z,R.yy.parser=this,typeof z.yylloc>"u"&&(z.yylloc={});var nt=z.yylloc;i.push(nt);var ut=z.options&&z.options.ranges;typeof R.yy.parseError=="function"?this.parseError=R.yy.parseError:this.parseError=Object.getPrototypeOf(this).parseError;function dt(Q){h.length=h.length-2*Q,$.length=$.length-Q,i.length=i.length-Q}_(dt,"popStack"),l(dt,"popStack");function ft(){var Q;return Q=u.pop()||z.lex()||H,typeof Q!="number"&&(Q instanceof Array&&(u=Q,Q=u.pop()),Q=k.symbols_[Q]||Q),Q}_(ft,"lex"),l(ft,"lex");for(var U,at,J,Z,sn,Yt,ht={},xt,rt,de,wt;;){if(J=h[h.length-1],this.defaultActions[J]?Z=this.defaultActions[J]:((U===null||typeof U>"u")&&(U=ft()),Z=A[J]&&A[J][U]),typeof Z>"u"||!Z.length||!Z[0]){var $t="";wt=[];for(xt in A[J])this.terminals_[xt]&&xt>L&&wt.push("'"+this.terminals_[xt]+"'");z.showPosition?$t="Parse error on line "+(X+1)+`:
`+z.showPosition()+`
Expecting `+wt.join(", ")+", got '"+(this.terminals_[U]||U)+"'":$t="Parse error on line "+(X+1)+": Unexpected "+(U==H?"end of input":"'"+(this.terminals_[U]||U)+"'"),this.parseError($t,{text:z.match,token:this.terminals_[U]||U,line:z.yylineno,loc:nt,expected:wt})}if(Z[0]instanceof Array&&Z.length>1)throw new Error("Parse Error: multiple actions possible at state: "+J+", token: "+U);switch(Z[0]){case 1:h.push(U),$.push(z.yytext),i.push(z.yylloc),h.push(Z[1]),U=null,at?(U=at,at=null):(o=z.yyleng,c=z.yytext,X=z.yylineno,nt=z.yylloc,I>0&&I--);break;case 2:if(rt=this.productions_[Z[1]][1],ht.$=$[$.length-rt],ht._$={first_line:i[i.length-(rt||1)].first_line,last_line:i[i.length-1].last_line,first_column:i[i.length-(rt||1)].first_column,last_column:i[i.length-1].last_column},ut&&(ht._$.range=[i[i.length-(rt||1)].range[0],i[i.length-1].range[1]]),Yt=this.performAction.apply(ht,[c,o,X,R.yy,Z[1],$,i].concat(B)),typeof Yt<"u")return Yt;rt&&(h=h.slice(0,-1*rt*2),$=$.slice(0,-1*rt),i=i.slice(0,-1*rt)),h.push(this.productions_[Z[1]][0]),$.push(ht.$),i.push(ht._$),de=A[h[h.length-2]][h[h.length-1]],h.push(de);break;case 3:return!0}}return!0},"parse"),"parse")},v=(function(){var T={EOF:1,parseError:l(_(function(k,h){if(this.yy.parser)this.yy.parser.parseError(k,h);else throw new Error(k)},"parseError"),"parseError"),setInput:l(function(a,k){return this.yy=k||this.yy||{},this._input=a,this._more=this._backtrack=this.done=!1,this.yylineno=this.yyleng=0,this.yytext=this.matched=this.match="",this.conditionStack=["INITIAL"],this.yylloc={first_line:1,first_column:0,last_line:1,last_column:0},this.options.ranges&&(this.yylloc.range=[0,0]),this.offset=0,this},"setInput"),input:l(function(){var a=this._input[0];this.yytext+=a,this.yyleng++,this.offset++,this.match+=a,this.matched+=a;var k=a.match(/(?:\r\n?|\n).*/g);return k?(this.yylineno++,this.yylloc.last_line++):this.yylloc.last_column++,this.options.ranges&&this.yylloc.range[1]++,this._input=this._input.slice(1),a},"input"),unput:l(function(a){var k=a.length,h=a.split(/(?:\r\n?|\n)/g);this._input=a+this._input,this.yytext=this.yytext.substr(0,this.yytext.length-k),this.offset-=k;var u=this.match.split(/(?:\r\n?|\n)/g);this.match=this.match.substr(0,this.match.length-1),this.matched=this.matched.substr(0,this.matched.length-1),h.length-1&&(this.yylineno-=h.length-1);var $=this.yylloc.range;return this.yylloc={first_line:this.yylloc.first_line,last_line:this.yylineno+1,first_column:this.yylloc.first_column,last_column:h?(h.length===u.length?this.yylloc.first_column:0)+u[u.length-h.length].length-h[0].length:this.yylloc.first_column-k},this.options.ranges&&(this.yylloc.range=[$[0],$[0]+this.yyleng-k]),this.yyleng=this.yytext.length,this},"unput"),more:l(function(){return this._more=!0,this},"more"),reject:l(function(){if(this.options.backtrack_lexer)this._backtrack=!0;else return this.parseError("Lexical error on line "+(this.yylineno+1)+`. You can only invoke reject() in the lexer when the lexer is of the backtracking persuasion (options.backtrack_lexer = true).
`+this.showPosition(),{text:"",token:null,line:this.yylineno});return this},"reject"),less:l(function(a){this.unput(this.match.slice(a))},"less"),pastInput:l(function(){var a=this.matched.substr(0,this.matched.length-this.match.length);return(a.length>20?"...":"")+a.substr(-20).replace(/\n/g,"")},"pastInput"),upcomingInput:l(function(){var a=this.match;return a.length<20&&(a+=this._input.substr(0,20-a.length)),(a.substr(0,20)+(a.length>20?"...":"")).replace(/\n/g,"")},"upcomingInput"),showPosition:l(function(){var a=this.pastInput(),k=new Array(a.length+1).join("-");return a+this.upcomingInput()+`
`+k+"^"},"showPosition"),test_match:l(function(a,k){var h,u,$;if(this.options.backtrack_lexer&&($={yylineno:this.yylineno,yylloc:{first_line:this.yylloc.first_line,last_line:this.last_line,first_column:this.yylloc.first_column,last_column:this.yylloc.last_column},yytext:this.yytext,match:this.match,matches:this.matches,matched:this.matched,yyleng:this.yyleng,offset:this.offset,_more:this._more,_input:this._input,yy:this.yy,conditionStack:this.conditionStack.slice(0),done:this.done},this.options.ranges&&($.yylloc.range=this.yylloc.range.slice(0))),u=a[0].match(/(?:\r\n?|\n).*/g),u&&(this.yylineno+=u.length),this.yylloc={first_line:this.yylloc.last_line,last_line:this.yylineno+1,first_column:this.yylloc.last_column,last_column:u?u[u.length-1].length-u[u.length-1].match(/\r?\n?/)[0].length:this.yylloc.last_column+a[0].length},this.yytext+=a[0],this.match+=a[0],this.matches=a,this.yyleng=this.yytext.length,this.options.ranges&&(this.yylloc.range=[this.offset,this.offset+=this.yyleng]),this._more=!1,this._backtrack=!1,this._input=this._input.slice(a[0].length),this.matched+=a[0],h=this.performAction.call(this,this.yy,this,k,this.conditionStack[this.conditionStack.length-1]),this.done&&this._input&&(this.done=!1),h)return h;if(this._backtrack){for(var i in $)this[i]=$[i];return!1}return!1},"test_match"),next:l(function(){if(this.done)return this.EOF;this._input||(this.done=!0);var a,k,h,u;this._more||(this.yytext="",this.match="");for(var $=this._currentRules(),i=0;i<$.length;i++)if(h=this._input.match(this.rules[$[i]]),h&&(!k||h[0].length>k[0].length)){if(k=h,u=i,this.options.backtrack_lexer){if(a=this.test_match(h,$[i]),a!==!1)return a;if(this._backtrack){k=!1;continue}else return!1}else if(!this.options.flex)break}return k?(a=this.test_match(k,$[u]),a!==!1?a:!1):this._input===""?this.EOF:this.parseError("Lexical error on line "+(this.yylineno+1)+`. Unrecognized text.
`+this.showPosition(),{text:"",token:null,line:this.yylineno})},"next"),lex:l(_(function(){var k=this.next();return k||this.lex()},"lex"),"lex"),begin:l(_(function(k){this.conditionStack.push(k)},"begin"),"begin"),popState:l(_(function(){var k=this.conditionStack.length-1;return k>0?this.conditionStack.pop():this.conditionStack[0]},"popState"),"popState"),_currentRules:l(_(function(){return this.conditionStack.length&&this.conditionStack[this.conditionStack.length-1]?this.conditions[this.conditionStack[this.conditionStack.length-1]].rules:this.conditions.INITIAL.rules},"_currentRules"),"_currentRules"),topState:l(_(function(k){return k=this.conditionStack.length-1-Math.abs(k||0),k>=0?this.conditionStack[k]:"INITIAL"},"topState"),"topState"),pushState:l(_(function(k){this.begin(k)},"pushState"),"pushState"),stateStackSize:l(_(function(){return this.conditionStack.length},"stateStackSize"),"stateStackSize"),options:{"case-insensitive":!0},performAction:l(_(function(k,h,u,$){var i=$;switch(u){case 0:return this.begin("open_directive"),"open_directive";break;case 1:return this.begin("acc_title"),31;break;case 2:return this.popState(),"acc_title_value";break;case 3:return this.begin("acc_descr"),33;break;case 4:return this.popState(),"acc_descr_value";break;case 5:this.begin("acc_descr_multiline");break;case 6:this.popState();break;case 7:return"acc_descr_multiline_value";case 8:break;case 9:break;case 10:break;case 11:return 10;case 12:break;case 13:break;case 14:this.begin("href");break;case 15:this.popState();break;case 16:return 43;case 17:this.begin("callbackname");break;case 18:this.popState();break;case 19:this.popState(),this.begin("callbackargs");break;case 20:return 41;case 21:this.popState();break;case 22:return 42;case 23:this.begin("click");break;case 24:this.popState();break;case 25:return 40;case 26:return 4;case 27:return 22;case 28:return 23;case 29:return 24;case 30:return 25;case 31:return 26;case 32:return 28;case 33:return 27;case 34:return 29;case 35:return 12;case 36:return 13;case 37:return 14;case 38:return 15;case 39:return 16;case 40:return 17;case 41:return 18;case 42:return 20;case 43:return 21;case 44:return"date";case 45:return 30;case 46:return"accDescription";case 47:return 36;case 48:return 38;case 49:return 39;case 50:return":";case 51:return 6;case 52:return"INVALID"}},"anonymous"),"anonymous"),rules:[/^(?:%%\{)/i,/^(?:accTitle\s*:\s*)/i,/^(?:(?!\n||)*[^\n]*)/i,/^(?:accDescr\s*:\s*)/i,/^(?:(?!\n||)*[^\n]*)/i,/^(?:accDescr\s*\{\s*)/i,/^(?:[\}])/i,/^(?:[^\}]*)/i,/^(?:%%(?!\{)*[^\n]*)/i,/^(?:[^\}]%%*[^\n]*)/i,/^(?:%%*[^\n]*[\n]*)/i,/^(?:[\n]+)/i,/^(?:\s+)/i,/^(?:%[^\n]*)/i,/^(?:href[\s]+["])/i,/^(?:["])/i,/^(?:[^"]*)/i,/^(?:call[\s]+)/i,/^(?:\([\s]*\))/i,/^(?:\()/i,/^(?:[^(]*)/i,/^(?:\))/i,/^(?:[^)]*)/i,/^(?:click[\s]+)/i,/^(?:[\s\n])/i,/^(?:[^\s\n]*)/i,/^(?:gantt\b)/i,/^(?:dateFormat\s[^#\n;]+)/i,/^(?:inclusiveEndDates\b)/i,/^(?:topAxis\b)/i,/^(?:axisFormat\s[^#\n;]+)/i,/^(?:tickInterval\s[^#\n;]+)/i,/^(?:includes\s[^#\n;]+)/i,/^(?:excludes\s[^#\n;]+)/i,/^(?:todayMarker\s[^\n;]+)/i,/^(?:weekday\s+monday\b)/i,/^(?:weekday\s+tuesday\b)/i,/^(?:weekday\s+wednesday\b)/i,/^(?:weekday\s+thursday\b)/i,/^(?:weekday\s+friday\b)/i,/^(?:weekday\s+saturday\b)/i,/^(?:weekday\s+sunday\b)/i,/^(?:weekend\s+friday\b)/i,/^(?:weekend\s+saturday\b)/i,/^(?:\d\d\d\d-\d\d-\d\d\b)/i,/^(?:title\s[^\n]+)/i,/^(?:accDescription\s[^#\n;]+)/i,/^(?:section\s[^\n]+)/i,/^(?:[^:\n]+)/i,/^(?::[^#\n;]+)/i,/^(?::)/i,/^(?:$)/i,/^(?:.)/i],conditions:{acc_descr_multiline:{rules:[6,7],inclusive:!1},acc_descr:{rules:[4],inclusive:!1},acc_title:{rules:[2],inclusive:!1},callbackargs:{rules:[21,22],inclusive:!1},callbackname:{rules:[18,19,20],inclusive:!1},href:{rules:[15,16],inclusive:!1},click:{rules:[24,25],inclusive:!1},INITIAL:{rules:[0,1,3,5,8,9,10,11,12,13,14,17,23,26,27,28,29,30,31,32,33,34,35,36,37,38,39,40,41,42,43,44,45,46,47,48,49,50,51,52],inclusive:!0}}};return T})();m.lexer=v;function g(){this.yy={}}return _(g,"Parser"),l(g,"Parser"),g.prototype=m,m.Parser=g,new g})();Qt.parser=Qt;var mi=Qt;K.default.extend(Xe.default);K.default.extend(Ue.default);K.default.extend(qe.default);var He={friday:5,saturday:6},et="",ee="",ie=void 0,ne="",gt=[],pt=[],re=new Map,se=[],Et=[],vt="",ae="",Ze=["active","done","crit","milestone","vert"],oe=[],mt="",bt=!1,ce=!1,le="sunday",It="saturday",Kt=0,ki=l(function(){se=[],Et=[],vt="",oe=[],Ct=0,te=void 0,Mt=void 0,q=[],et="",ee="",ae="",ie=void 0,ne="",gt=[],pt=[],bt=!1,ce=!1,Kt=0,re=new Map,mt="",ke(),le="sunday",It="saturday"},"clear"),yi=l(function(t){mt=t},"setDiagramId"),gi=l(function(t){ee=t},"setAxisFormat"),pi=l(function(){return ee},"getAxisFormat"),vi=l(function(t){ie=t},"setTickInterval"),Ti=l(function(){return ie},"getTickInterval"),bi=l(function(t){ne=t},"setTodayMarker"),xi=l(function(){return ne},"getTodayMarker"),wi=l(function(t){et=t},"setDateFormat"),_i=l(function(){bt=!0},"enableInclusiveEndDates"),Di=l(function(){return bt},"endDatesAreInclusive"),Si=l(function(){ce=!0},"enableTopAxis"),Ci=l(function(){return ce},"topAxisEnabled"),Mi=l(function(t){ae=t},"setDisplayMode"),Ei=l(function(){return ae},"getDisplayMode"),Ii=l(function(){return et},"getDateFormat"),Qe=l((t,e)=>{let r=e.toLowerCase().split(/[\s,]+/).filter(n=>n!=="");return[...new Set([...t,...r])]},"mergeTokens"),Yi=l(function(t){gt=Qe(gt,t)},"setIncludes"),$i=l(function(){return gt},"getIncludes"),Ai=l(function(t){pt=Qe(pt,t)},"setExcludes"),Li=l(function(){return pt},"getExcludes"),Fi=l(function(){return re},"getLinks"),Oi=l(function(t){vt=t,se.push(t)},"addSection"),Wi=l(function(){return se},"getSections"),Vi=l(function(){let t=Be(),e=10,r=0;for(;!t&&r<e;)t=Be(),r++;return Et=q,Et},"getTasks"),Ke=l(function(t,e,r,n){let s=t.format(e.trim()),d=t.format("YYYY-MM-DD");return n.includes(s)||n.includes(d)?!1:r.includes("weekends")&&(t.isoWeekday()===He[It]||t.isoWeekday()===He[It]+1)||r.includes(t.format("dddd").toLowerCase())?!0:r.includes(s)||r.includes(d)},"isInvalidDate"),Pi=l(function(t){le=t},"setWeekday"),zi=l(function(){return le},"getWeekday"),Ri=l(function(t){It=t},"setWeekend"),Je=l(function(t,e,r,n){if(!r.length||t.manualEndTime)return;let s;t.startTime instanceof Date?s=(0,K.default)(t.startTime):s=(0,K.default)(t.startTime,e,!0),s=s.add(1,"d");let d;t.endTime instanceof Date?d=(0,K.default)(t.endTime):d=(0,K.default)(t.endTime,e,!0);let[y,S]=Ni(s,d,e,r,n);t.endTime=y.toDate(),t.renderEndTime=S},"checkTaskDates"),Ni=l(function(t,e,r,n,s){let d=!1,y=null,S=e.add(1e4,"d");for(;t<=e;){if(d||(y=e.toDate()),d=Ke(t,r,n,s),d&&(e=e.add(1,"d"),e>S))throw new Error("Failed to find a valid date that was not excluded by `excludes` after 10,000 iterations.");t=t.add(1,"d")}return[e,y]},"fixTaskDates"),ti=l(function(t,e){tt.warn(`Gantt: the "${t}" statement references unknown task id(s): ${e.join(", ")}. Make sure the referenced tasks exist and declare an id. Milestones need both an id and a duration, e.g. "Milestone :milestone, m1, 2023-01-01, 0d".`)},"warnAboutUnknownTaskIds"),Jt=l(function(t,e,r){if(r=r.trim(),l(S=>{let w=S.trim();return w==="x"||w==="X"},"isTimestampFormat")(e)&&/^\d+$/.test(r))return new Date(Number(r));let d=/^after\s+(?<ids>[\d\w- ]+)/.exec(r);if(d!==null){let S=null,w=[],P=d.groups.ids.split(" ").filter(N=>N!=="");for(let N of P){let C=lt(N);if(C===void 0){w.push(N);continue}(!S||C.endTime>S.endTime)&&(S=C)}if(w.length>0&&ti("after",w),S)return S.endTime;let Y=new Date;return Y.setHours(0,0,0,0),Y}let y=(0,K.default)(r,e.trim(),!0);if(y.isValid())return y.toDate();{tt.debug("Invalid date:"+r),tt.debug("With date format:"+e.trim());let S=new Date(r);if(S===void 0||isNaN(S.getTime())||S.getFullYear()<-1e4||S.getFullYear()>1e4)throw new Error("Invalid date:"+r);return S}},"getStartDate"),ei=l(function(t){let e=/^(\d+(?:\.\d+)?)([Mdhmswy]|ms)$/.exec(t.trim());return e!==null?[Number.parseFloat(e[1]),e[2]]:[NaN,"ms"]},"parseDuration"),ii=l(function(t,e,r,n=!1){r=r.trim();let d=/^until\s+(?<ids>[\d\w- ]+)/.exec(r);if(d!==null){let Y=null,N=[],C=d.groups.ids.split(" ").filter(b=>b!=="");for(let b of C){let D=lt(b);if(D===void 0){N.push(b);continue}(!Y||D.startTime<Y.startTime)&&(Y=D)}if(N.length>0&&ti("until",N),Y)return Y.startTime;let M=new Date;return M.setHours(0,0,0,0),M}let y=(0,K.default)(r,e.trim(),!0);if(y.isValid())return n&&(y=y.add(1,"d")),y.toDate();let S=(0,K.default)(t),[w,P]=ei(r);if(Number.isNaN(w))tt.warn(`Gantt: "${r}" is neither a valid date for the "${e.trim()}" date format nor a valid duration (e.g. "3d"), so it is ignored and the task gets a zero duration. Milestones need a duration too, e.g. "Milestone :milestone, m1, 2023-01-01, 0d".`);else{let Y=S.add(w,P);Y.isValid()&&(S=Y)}return S.toDate()},"getEndDate"),Ct=0,yt=l(function(t){return t===void 0?(Ct=Ct+1,"task"+Ct):t},"parseId"),Hi=l(function(t,e){let r;e.substr(0,1)===":"?r=e.substr(1,e.length):r=e;let n=r.split(","),s={};ue(n,s,Ze);for(let y=0;y<n.length;y++)n[y]=n[y].trim();let d="";switch(n.length){case 1:s.id=yt(),s.startTime=t.endTime,d=n[0];break;case 2:s.id=yt(),s.startTime=Jt(void 0,et,n[0]),d=n[1];break;case 3:s.id=yt(n[0]),s.startTime=Jt(void 0,et,n[1]),d=n[2];break;default:}return d&&(s.endTime=ii(s.startTime,et,d,bt),s.manualEndTime=(0,K.default)(d,"YYYY-MM-DD",!0).isValid(),Je(s,et,pt,gt)),s},"compileData"),Bi=l(function(t,e){let r;e.substr(0,1)===":"?r=e.substr(1,e.length):r=e;let n=r.split(","),s={};ue(n,s,Ze);for(let d=0;d<n.length;d++)n[d]=n[d].trim();switch(n.length){case 1:s.id=yt(),s.startTime={type:"prevTaskEnd",id:t},s.endTime={data:n[0]};break;case 2:s.id=yt(),s.startTime={type:"getStartDate",startData:n[0]},s.endTime={data:n[1]};break;case 3:s.id=yt(n[0]),s.startTime={type:"getStartDate",startData:n[1]},s.endTime={data:n[2]};break;default:}return s},"parseData"),te,Mt,q=[],ni={},ji=l(function(t,e){let r={section:vt,type:vt,processed:!1,manualEndTime:!1,renderEndTime:null,raw:{data:e},task:t,classes:[]},n=Bi(Mt,e);r.raw.startTime=n.startTime,r.raw.endTime=n.endTime,r.id=n.id,r.prevTaskId=Mt,r.active=n.active,r.done=n.done,r.crit=n.crit,r.milestone=n.milestone,r.vert=n.vert,r.vert?r.order=-1:(r.order=Kt,Kt++);let s=q.push(r);Mt=r.id,ni[r.id]=s-1},"addTask"),lt=l(function(t){let e=ni[t];return q[e]},"findTaskById"),Gi=l(function(t,e){let r={section:vt,type:vt,description:t,task:t,classes:[]},n=Hi(te,e);r.startTime=n.startTime,r.endTime=n.endTime,r.id=n.id,r.active=n.active,r.done=n.done,r.crit=n.crit,r.milestone=n.milestone,r.vert=n.vert,te=r,Et.push(r)},"addTaskOrg"),Be=l(function(){let t=l(function(r){let n=q[r],s="";switch(q[r].raw.startTime.type){case"prevTaskEnd":{let d=lt(n.prevTaskId);n.startTime=d.endTime;break}case"getStartDate":s=Jt(void 0,et,q[r].raw.startTime.startData),s&&(q[r].startTime=s);break}return q[r].startTime&&(q[r].endTime=ii(q[r].startTime,et,q[r].raw.endTime.data,bt),q[r].endTime&&(q[r].processed=!0,q[r].manualEndTime=(0,K.default)(q[r].raw.endTime.data,"YYYY-MM-DD",!0).isValid(),Je(q[r],et,pt,gt))),q[r].processed},"compileTask"),e=!0;for(let[r,n]of q.entries())t(r),e=e&&n.processed;return e},"compileTasks"),Xi=l(function(t,e){let r=e;ct().securityLevel!=="loose"&&(r=(0,Ge.sanitizeUrl)(e)),t.split(",").forEach(function(n){lt(n)!==void 0&&(si(n,()=>{window.open(r,"_self")}),re.set(n,r))}),ri(t,"clickable")},"setLink"),ri=l(function(t,e){t.split(",").forEach(function(r){let n=lt(r);n!==void 0&&n.classes.push(e)})},"setClass"),Ui=l(function(t,e,r){if(ct().securityLevel!=="loose"||e===void 0)return;let n=[];if(typeof r=="string"){n=r.split(/,(?=(?:(?:[^"]*"){2})*[^"]*$)/);for(let d=0;d<n.length;d++){let y=n[d].trim();y.startsWith('"')&&y.endsWith('"')&&(y=y.substr(1,y.length-2)),n[d]=y}}n.length===0&&n.push(t),lt(t)!==void 0&&si(t,()=>{Le.runFunc(e,...n)})},"setClickFun"),si=l(function(t,e){oe.push(function(){let r=mt?`${mt}-${t}`:t,n=document.querySelector(`[id="${r}"]`);n!==null&&n.addEventListener("click",function(){e()})},function(){let r=mt?`${mt}-${t}`:t,n=document.querySelector(`[id="${r}-text"]`);n!==null&&n.addEventListener("click",function(){e()})})},"pushFun"),qi=l(function(t,e,r){t.split(",").forEach(function(n){Ui(n,e,r)}),ri(t,"clickable")},"setClickEvent"),Zi=l(function(t){oe.forEach(function(e){e(t)})},"bindFunctions"),Qi={getConfig:l(()=>ct().gantt,"getConfig"),clear:ki,setDateFormat:wi,getDateFormat:Ii,enableInclusiveEndDates:_i,endDatesAreInclusive:Di,enableTopAxis:Si,topAxisEnabled:Ci,setAxisFormat:gi,getAxisFormat:pi,setTickInterval:vi,getTickInterval:Ti,setTodayMarker:bi,getTodayMarker:xi,setAccTitle:ye,getAccTitle:ge,setDiagramTitle:Te,getDiagramTitle:be,setDiagramId:yi,setDisplayMode:Mi,getDisplayMode:Ei,setAccDescription:pe,getAccDescription:ve,addSection:Oi,getSections:Wi,getTasks:Vi,addTask:ji,findTaskById:lt,addTaskOrg:Gi,setIncludes:Yi,getIncludes:$i,setExcludes:Ai,getExcludes:Li,setClickEvent:qi,setLink:Xi,getLinks:Fi,bindFunctions:Zi,parseDuration:ei,isInvalidDate:Ke,setWeekday:Pi,getWeekday:zi,setWeekend:Ri};function ue(t,e,r){let n=!0;for(;n;)n=!1,r.forEach(function(s){let d="^\\s*"+s+"\\s*$",y=new RegExp(d);t[0].match(y)&&(e[s]=!0,t.shift(1),n=!0)})}_(ue,"getTaskTags");l(ue,"getTaskTags");kt.default.extend(ai.default);var Ki=l(function(){tt.debug("Something is calling, setConf, remove the call")},"setConf"),je={monday:Se,tuesday:Ce,wednesday:Me,thursday:Ee,friday:Ie,saturday:Ye,sunday:De},Ji=l((t,e)=>{let r=[...t].map(()=>-1/0),n=[...t].sort((d,y)=>d.startTime-y.startTime||d.order-y.order),s=0;for(let d of n)for(let y=0;y<r.length;y++)if(d.startTime>=r[y]){r[y]=d.endTime,d.order=y+e,y>s&&(s=y);break}return s},"getMaxIntersections"),st,Zt=1e4,tn=l(function(t,e,r,n){let s=ct().gantt;n.db.setDiagramId(e);let d=ct().securityLevel,y;d==="sandbox"&&(y=Tt("#i"+e));let S=d==="sandbox"?Tt(y.nodes()[0].contentDocument.body):Tt("body"),w=d==="sandbox"?y.nodes()[0].contentDocument:document,P=w.getElementById(e);st=P.parentElement.offsetWidth,st===void 0&&(st=1200),s.useWidth!==void 0&&(st=s.useWidth);let Y=n.db.getTasks(),N=Y.filter(m=>!m.vert),C=[];for(let m of N)C.push(m.type);C=O(C);let M={},b=2*s.topPadding;if(n.db.getDisplayMode()==="compact"||s.displayMode==="compact"){let m={};for(let g of N)m[g.section]===void 0?m[g.section]=[g]:m[g.section].push(g);let v=0;for(let g of Object.keys(m)){let T=Ji(m[g],v)+1;v+=T,b+=T*(s.barHeight+s.barGap),M[g]=T}}else{b+=N.length*(s.barHeight+s.barGap);for(let m of C)M[m]=N.filter(v=>v.type===m).length}P.setAttribute("viewBox","0 0 "+st+" "+b);let D=S.select(`[id="${e}"]`),x=Ae().domain([we(Y,function(m){return m.startTime}),xe(Y,function(m){return m.endTime})]).rangeRound([0,st-s.leftPadding-s.rightPadding]);function W(m,v){let g=m.startTime,T=v.startTime,a=0;return g>T?a=1:g<T&&(a=-1),a}_(W,"taskCompare"),l(W,"taskCompare"),Y.sort(W),j(Y,st,b),me(D,b,st,s.useMaxWidth),D.append("text").text(n.db.getDiagramTitle()).attr("x",st/2).attr("y",s.titleTopMargin).attr("class","titleText");function j(m,v,g){let T=s.barHeight,a=T+s.barGap,k=s.topPadding,h=s.leftPadding,u=$e().domain([0,C.length]).range(["#00B9FA","#F95002"]).interpolate(_e);F(a,k,h,v,g,m,n.db.getExcludes(),n.db.getIncludes()),f(h,k,v,g),G(m,a,k,h,T,u,v,g),E(a,k,h,T,u),V(h,k,v,g)}_(j,"makeGantt"),l(j,"makeGantt");function G(m,v,g,T,a,k,h){m.sort((o,I)=>o.vert===I.vert?0:o.vert?1:-1);let u=m.filter(o=>!o.vert),i=[...new Set(u.map(o=>o.order))].map(o=>u.find(I=>I.order===o));D.append("g").selectAll("rect").data(i).enter().append("rect").attr("x",0).attr("y",function(o,I){return I=o.order,I*v+g-2}).attr("width",function(){return h-s.rightPadding/2}).attr("height",v).attr("class",function(o){for(let[I,L]of C.entries())if(o.type===L)return"section section"+I%s.numberSectionStyles;return"section section0"}).enter();let A=D.append("g").selectAll("rect").data(m).enter(),c=n.db.getLinks();if(A.append("rect").attr("id",function(o){return e+"-"+o.id}).attr("rx",3).attr("ry",3).attr("x",function(o){return o.milestone?x(o.startTime)+T+.5*(x(o.endTime)-x(o.startTime))-.5*a:x(o.startTime)+T}).attr("y",function(o,I){return I=o.order,o.vert?s.gridLineStartPadding:I*v+g}).attr("width",function(o){return o.milestone?a:o.vert?.08*a:x(o.renderEndTime||o.endTime)-x(o.startTime)}).attr("height",function(o){return o.vert?u.length*(s.barHeight+s.barGap)+s.barHeight*2:a}).attr("transform-origin",function(o,I){return I=o.order,(x(o.startTime)+T+.5*(x(o.endTime)-x(o.startTime))).toString()+"px "+(I*v+g+.5*a).toString()+"px"}).attr("class",function(o){let I="task",L="";o.classes.length>0&&(L=o.classes.join(" "));let H=0;for(let[z,R]of C.entries())o.type===R&&(H=z%s.numberSectionStyles);let B="";return o.active?o.crit?B+=" activeCrit":B=" active":o.done?o.crit?B=" doneCrit":B=" done":o.crit&&(B+=" crit"),B.length===0&&(B=" task"),o.milestone&&(B=" milestone "+B),o.vert&&(B=" vert "+B),B+=H,B+=" "+L,I+B}),A.append("text").attr("id",function(o){return e+"-"+o.id+"-text"}).text(function(o){return o.task}).attr("font-size",s.fontSize).attr("x",function(o){let I=x(o.startTime),L=x(o.renderEndTime||o.endTime);if(o.milestone&&(I+=.5*(x(o.endTime)-x(o.startTime))-.5*a,L=I+a),o.vert)return x(o.startTime)+T;let H=this.getBBox().width;return H>L-I?L+H+1.5*s.leftPadding>h?I+T-5:L+T+5:(L-I)/2+I+T}).attr("y",function(o,I){return o.vert?s.gridLineStartPadding+u.length*(s.barHeight+s.barGap)+60:(I=o.order,I*v+s.barHeight/2+(s.fontSize/2-2)+g)}).attr("text-height",a).attr("class",function(o){let I=x(o.startTime),L=x(o.endTime);o.milestone&&(L=I+a);let H=this.getBBox().width,B="";o.classes.length>0&&(B=o.classes.join(" "));let z=0;for(let[it,nt]of C.entries())o.type===nt&&(z=it%s.numberSectionStyles);let R="";return o.active&&(o.crit?R="activeCritText"+z:R="activeText"+z),o.done?o.crit?R=R+" doneCritText"+z:R=R+" doneText"+z:o.crit&&(R=R+" critText"+z),o.milestone&&(R+=" milestoneText"),o.vert&&(R+=" vertText"),H>L-I?L+H+1.5*s.leftPadding>h?B+" taskTextOutsideLeft taskTextOutside"+z+" "+R:B+" taskTextOutsideRight taskTextOutside"+z+" "+R+" width-"+H:B+" taskText taskText"+z+" "+R+" width-"+H}),ct().securityLevel==="sandbox"){let o;o=Tt("#i"+e);let I=o.nodes()[0].contentDocument;A.filter(function(L){return c.has(L.id)}).each(function(L){var H=I.querySelector("#"+CSS.escape(e+"-"+L.id)),B=I.querySelector("#"+CSS.escape(e+"-"+L.id+"-text"));let z=H.parentNode;var R=I.createElement("a");R.setAttribute("xlink:href",c.get(L.id)),R.setAttribute("target","_top"),z.appendChild(R),R.appendChild(H),R.appendChild(B)})}}_(G,"drawRects"),l(G,"drawRects");function F(m,v,g,T,a,k,h,u){if(h.length===0&&u.length===0)return;let $,i;for(let{startTime:L,endTime:H}of k)($===void 0||L<$)&&($=L),(i===void 0||H>i)&&(i=H);if(!$||!i)return;if((0,kt.default)(i).diff((0,kt.default)($),"year")>5){tt.warn("The difference between the min and max time is more than 5 years. This will cause performance issues. Skipping drawing exclude days.");return}let A=n.db.getDateFormat(),c=[],X=null,o=(0,kt.default)($);for(;o.valueOf()<=i;)n.db.isInvalidDate(o,A,h,u)?X?X.end=o:X={start:o,end:o}:X&&(c.push(X),X=null),o=o.add(1,"d");D.append("g").selectAll("rect").data(c).enter().append("rect").attr("id",L=>e+"-exclude-"+L.start.format("YYYY-MM-DD")).attr("x",L=>x(L.start.startOf("day"))+g).attr("y",s.gridLineStartPadding).attr("width",L=>x(L.end.endOf("day"))-x(L.start.startOf("day"))).attr("height",a-v-s.gridLineStartPadding).attr("transform-origin",function(L,H){return(x(L.start)+g+.5*(x(L.end)-x(L.start))).toString()+"px "+(H*m+.5*a).toString()+"px"}).attr("class","exclude-range")}_(F,"drawExcludeDays"),l(F,"drawExcludeDays");function p(m,v,g,T){if(g<=0||m>v)return 1/0;let a=v-m,k=kt.default.duration({[T??"day"]:g}).asMilliseconds();return k<=0?1/0:Math.ceil(a/k)}_(p,"getEstimatedTickCount"),l(p,"getEstimatedTickCount");function f(m,v,g,T){let a=n.db.getDateFormat(),k=n.db.getAxisFormat(),h;k?h=k:a==="D"?h="%d":h=s.axisFormat??"%Y-%m-%d";let u=Ve(x).tickSize(-T+v+s.gridLineStartPadding).tickFormat(Pt(h)),i=/^([1-9]\d*)(millisecond|second|minute|hour|day|week|month)$/.exec(n.db.getTickInterval()||s.tickInterval);if(i!==null){let A=parseInt(i[1],10);if(isNaN(A)||A<=0)tt.warn(`Invalid tick interval value: "${i[1]}". Skipping custom tick interval.`);else{let c=i[2],X=n.db.getWeekday()||s.weekday,o=x.domain(),I=o[0],L=o[1],H=p(I,L,A,c);if(H>Zt)tt.warn(`The tick interval "${A}${c}" would generate ${H} ticks, which exceeds the maximum allowed (${Zt}). This may indicate an invalid date or time range. Skipping custom tick interval.`);else switch(c){case"millisecond":u.ticks(At.every(A));break;case"second":u.ticks(Lt.every(A));break;case"minute":u.ticks(Ft.every(A));break;case"hour":u.ticks(Ot.every(A));break;case"day":u.ticks(Wt.every(A));break;case"week":u.ticks(je[X].every(A));break;case"month":u.ticks(Vt.every(A));break}}}if(D.append("g").attr("class","grid").attr("transform","translate("+m+", "+(T-50)+")").call(u).selectAll("text").style("text-anchor","middle").attr("fill","#000").attr("stroke","none").attr("font-size",10).attr("dy","1em"),n.db.topAxisEnabled()||s.topAxis){let A=We(x).tickSize(-T+v+s.gridLineStartPadding).tickFormat(Pt(h));if(i!==null){let c=parseInt(i[1],10);if(isNaN(c)||c<=0)tt.warn(`Invalid tick interval value: "${i[1]}". Skipping custom tick interval.`);else{let X=i[2],o=n.db.getWeekday()||s.weekday,I=x.domain(),L=I[0],H=I[1];if(p(L,H,c,X)<=Zt)switch(X){case"millisecond":A.ticks(At.every(c));break;case"second":A.ticks(Lt.every(c));break;case"minute":A.ticks(Ft.every(c));break;case"hour":A.ticks(Ot.every(c));break;case"day":A.ticks(Wt.every(c));break;case"week":A.ticks(je[o].every(c));break;case"month":A.ticks(Vt.every(c));break}}}D.append("g").attr("class","grid").attr("transform","translate("+m+", "+v+")").call(A).selectAll("text").style("text-anchor","middle").attr("fill","#000").attr("stroke","none").attr("font-size",10)}}_(f,"makeGrid"),l(f,"makeGrid");function E(m,v){let g=0,T=Object.keys(M).map(a=>[a,M[a]]);D.append("g").selectAll("text").data(T).enter().append(function(a){let k=a[0].split(he.lineBreakRegex),h=-(k.length-1)/2,u=w.createElementNS("http://www.w3.org/2000/svg","text");u.setAttribute("dy",h+"em");for(let[$,i]of k.entries()){let A=w.createElementNS("http://www.w3.org/2000/svg","tspan");A.setAttribute("alignment-baseline","central"),A.setAttribute("x","10"),$>0&&A.setAttribute("dy","1em"),A.textContent=i,u.appendChild(A)}return u}).attr("x",10).attr("y",function(a,k){if(k>0)for(let h=0;h<k;h++)return g+=T[k-1][1],a[1]*m/2+g*m+v;else return a[1]*m/2+v}).attr("font-size",s.sectionFontSize).attr("class",function(a){for(let[k,h]of C.entries())if(a[0]===h)return"sectionTitle sectionTitle"+k%s.numberSectionStyles;return"sectionTitle"})}_(E,"vertLabels"),l(E,"vertLabels");function V(m,v,g,T){let a=n.db.getTodayMarker();if(a==="off")return;let k=D.append("g").attr("class","today"),h=new Date,u=k.append("line");u.attr("x1",x(h)+m).attr("x2",x(h)+m).attr("y1",s.titleTopMargin).attr("y2",T-s.titleTopMargin).attr("class","today"),a!==""&&u.attr("style",a.replace(/,/g,";"))}_(V,"drawToday"),l(V,"drawToday");function O(m){let v={},g=[];for(let T=0,a=m.length;T<a;++T)Object.prototype.hasOwnProperty.call(v,m[T])||(v[m[T]]=!0,g.push(m[T]));return g}_(O,"checkUnique"),l(O,"checkUnique")},"draw"),en={setConf:Ki,draw:tn},nn=l(t=>`
  .mermaid-main-font {
        font-family: ${t.fontFamily};
  }

  .exclude-range {
    fill: ${t.excludeBkgColor};
  }

  .section {
    stroke: none;
    opacity: 0.2;
  }

  .section0 {
    fill: ${t.sectionBkgColor};
  }

  .section2 {
    fill: ${t.sectionBkgColor2};
  }

  .section1,
  .section3 {
    fill: ${t.altSectionBkgColor};
    opacity: 0.2;
  }

  .sectionTitle0 {
    fill: ${t.titleColor};
  }

  .sectionTitle1 {
    fill: ${t.titleColor};
  }

  .sectionTitle2 {
    fill: ${t.titleColor};
  }

  .sectionTitle3 {
    fill: ${t.titleColor};
  }

  .sectionTitle {
    text-anchor: start;
    font-family: ${t.fontFamily};
  }


  /* Grid and axis */

  .grid .tick {
    stroke: ${t.gridColor};
    opacity: 0.8;
    shape-rendering: crispEdges;
  }

  .grid .tick text {
    font-family: ${t.fontFamily};
    fill: ${t.textColor};
  }

  .grid path {
    stroke-width: 0;
  }


  /* Today line */

  .today {
    fill: none;
    stroke: ${t.todayLineColor};
    stroke-width: 2px;
  }


  /* Task styling */

  /* Default task */

  .task {
    stroke-width: 2;
  }

  .taskText {
    text-anchor: middle;
    font-family: ${t.fontFamily};
  }

  .taskTextOutsideRight {
    fill: ${t.taskTextDarkColor};
    text-anchor: start;
    font-family: ${t.fontFamily};
  }

  .taskTextOutsideLeft {
    fill: ${t.taskTextDarkColor};
    text-anchor: end;
  }


  /* Special case clickable */

  .task.clickable {
    cursor: pointer;
  }

  .taskText.clickable {
    cursor: pointer;
    fill: ${t.taskTextClickableColor} !important;
    font-weight: bold;
  }

  .taskTextOutsideLeft.clickable {
    cursor: pointer;
    fill: ${t.taskTextClickableColor} !important;
    font-weight: bold;
  }

  .taskTextOutsideRight.clickable {
    cursor: pointer;
    fill: ${t.taskTextClickableColor} !important;
    font-weight: bold;
  }


  /* Specific task settings for the sections*/

  .taskText0,
  .taskText1,
  .taskText2,
  .taskText3 {
    fill: ${t.taskTextColor};
  }

  .task0,
  .task1,
  .task2,
  .task3 {
    fill: ${t.taskBkgColor};
    stroke: ${t.taskBorderColor};
  }

  .taskTextOutside0,
  .taskTextOutside2
  {
    fill: ${t.taskTextOutsideColor};
  }

  .taskTextOutside1,
  .taskTextOutside3 {
    fill: ${t.taskTextOutsideColor};
  }


  /* Active task */

  .active0,
  .active1,
  .active2,
  .active3 {
    fill: ${t.activeTaskBkgColor};
    stroke: ${t.activeTaskBorderColor};
  }

  .activeText0,
  .activeText1,
  .activeText2,
  .activeText3 {
    fill: ${t.taskTextDarkColor} !important;
  }


  /* Completed task */

  .done0,
  .done1,
  .done2,
  .done3 {
    stroke: ${t.doneTaskBorderColor};
    fill: ${t.doneTaskBkgColor};
    stroke-width: 2;
  }

  .doneText0,
  .doneText1,
  .doneText2,
  .doneText3 {
    fill: ${t.taskTextDarkColor} !important;
  }

  /* Done task text displayed outside the bar sits against the diagram background,
     not against the done-task bar, so it must use the outside/contrast color. */
  .doneText0.taskTextOutsideLeft,
  .doneText0.taskTextOutsideRight,
  .doneText1.taskTextOutsideLeft,
  .doneText1.taskTextOutsideRight,
  .doneText2.taskTextOutsideLeft,
  .doneText2.taskTextOutsideRight,
  .doneText3.taskTextOutsideLeft,
  .doneText3.taskTextOutsideRight {
    fill: ${t.taskTextOutsideColor} !important;
  }


  /* Tasks on the critical line */

  .crit0,
  .crit1,
  .crit2,
  .crit3 {
    stroke: ${t.critBorderColor};
    fill: ${t.critBkgColor};
    stroke-width: 2;
  }

  .activeCrit0,
  .activeCrit1,
  .activeCrit2,
  .activeCrit3 {
    stroke: ${t.critBorderColor};
    fill: ${t.activeTaskBkgColor};
    stroke-width: 2;
  }

  .doneCrit0,
  .doneCrit1,
  .doneCrit2,
  .doneCrit3 {
    stroke: ${t.critBorderColor};
    fill: ${t.doneTaskBkgColor};
    stroke-width: 2;
    cursor: pointer;
    shape-rendering: crispEdges;
  }

  .milestone {
    transform: rotate(45deg) scale(0.8,0.8);
  }

  .milestoneText {
    font-style: italic;
  }
  .doneCritText0,
  .doneCritText1,
  .doneCritText2,
  .doneCritText3 {
    fill: ${t.taskTextDarkColor} !important;
  }

  /* Done-crit task text outside the bar \u2014 same reasoning as doneText above. */
  .doneCritText0.taskTextOutsideLeft,
  .doneCritText0.taskTextOutsideRight,
  .doneCritText1.taskTextOutsideLeft,
  .doneCritText1.taskTextOutsideRight,
  .doneCritText2.taskTextOutsideLeft,
  .doneCritText2.taskTextOutsideRight,
  .doneCritText3.taskTextOutsideLeft,
  .doneCritText3.taskTextOutsideRight {
    fill: ${t.taskTextOutsideColor} !important;
  }

  .vert {
    stroke: ${t.vertLineColor};
  }

  .vertText {
    font-size: 15px;
    text-anchor: middle;
    fill: ${t.vertLineColor} !important;
  }

  .activeCritText0,
  .activeCritText1,
  .activeCritText2,
  .activeCritText3 {
    fill: ${t.taskTextDarkColor} !important;
  }

  .titleText {
    text-anchor: middle;
    font-size: 18px;
    fill: ${t.titleColor||t.textColor};
    font-family: ${t.fontFamily};
  }
`,"getStyles"),rn=nn,yn={parser:mi,db:Qi,renderer:en,styles:rn};export{yn as diagram};
//# sourceMappingURL=ganttDiagram-23REXITY-XFLT7D5E.js.map
