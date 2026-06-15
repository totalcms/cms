import{p as $e}from"./chunk-QMHG27QQ.js";import{a as Je}from"./chunk-RQPVFJNQ.js";import{N as le,O as ue,T as de,U as fe,V as he,W as me,X as ke,Y as ye,Z as ge,_ as ct}from"./chunk-VKIXLSQV.js";import{A as Me,B as Ee,C as Ie,D as Ot,E as Wt,F as Ye,a as ce,b as c,d as st,f as pe,g as ve,h as Te,i as be,j as pt,k as xe,q as we,r as Yt,s as $t,t as Lt,u as At,v as Ft,w as _e,x as De,y as Se,z as Ce}from"./chunk-2UWKIQCG.js";import{a as S,b as _t,c as ot}from"./chunk-B4JUHIHW.js";var Le=_t((Pt,Vt)=>{(function(t,i){typeof Pt=="object"&&typeof Vt<"u"?Vt.exports=i():typeof define=="function"&&define.amd?define(i):(t=typeof globalThis<"u"?globalThis:t||self).dayjs_plugin_isoWeek=i()})(Pt,function(){"use strict";var t="day";return function(i,a,n){var r=S(function(D){return D.add(4-D.isoWeekday(),t)},"a"),u=a.prototype;u.isoWeekYear=function(){return r(this).year()},u.isoWeek=function(D){if(!this.$utils().u(D))return this.add(7*(D-this.isoWeek()),t);var C,V,M,P,H=r(this),z=(C=this.isoWeekYear(),V=this.$u,M=(V?n.utc:n)().year(C).startOf("year"),P=4-M.isoWeekday(),M.isoWeekday()>4&&(P+=7),M.add(P,t));return H.diff(z,"week")+1},u.isoWeekday=function(D){return this.$utils().u(D)?this.day()||7:this.day(this.day()%7?D:D-7)};var p=u.startOf;u.startOf=function(D,C){var V=this.$utils(),M=!!V.u(C)||C;return V.p(D)==="isoweek"?M?this.date(this.date()-(this.isoWeekday()-1)).startOf("day"):this.date(this.date()-1-(this.isoWeekday()-1)+7).endOf("day"):p.bind(this)(D,C)}}})});var Ae=_t((Nt,Rt)=>{(function(t,i){typeof Nt=="object"&&typeof Rt<"u"?Rt.exports=i():typeof define=="function"&&define.amd?define(i):(t=typeof globalThis<"u"?globalThis:t||self).dayjs_plugin_customParseFormat=i()})(Nt,function(){"use strict";var t={LTS:"h:mm:ss A",LT:"h:mm A",L:"MM/DD/YYYY",LL:"MMMM D, YYYY",LLL:"MMMM D, YYYY h:mm A",LLLL:"dddd, MMMM D, YYYY h:mm A"},i=/(\[[^[]*\])|([-_:/.,()\s]+)|(A|a|Q|YYYY|YY?|ww?|MM?M?M?|Do|DD?|hh?|HH?|mm?|ss?|S{1,3}|z|ZZ?)/g,a=/\d/,n=/\d\d/,r=/\d\d?/,u=/\d*[^-_:/,()\s\d]+/,p={},D=S(function(x){return(x=+x)+(x>68?1900:2e3)},"a"),C=S(function(x){return function(k){this[x]=+k}},"f"),V=[/[+-]\d\d:?(\d\d)?|Z/,function(x){(this.zone||(this.zone={})).offset=function(k){if(!k||k==="Z")return 0;var O=k.match(/([+-]|\d\d)/g),L=60*O[1]+(+O[2]||0);return L===0?0:O[0]==="+"?-L:L}(x)}],M=S(function(x){var k=p[x];return k&&(k.indexOf?k:k.s.concat(k.f))},"u"),P=S(function(x,k){var O,L=p.meridiem;if(L){for(var X=1;X<=24;X+=1)if(x.indexOf(L(X,0,k))>-1){O=X>12;break}}else O=x===(k?"pm":"PM");return O},"d"),H={A:[u,function(x){this.afternoon=P(x,!1)}],a:[u,function(x){this.afternoon=P(x,!0)}],Q:[a,function(x){this.month=3*(x-1)+1}],S:[a,function(x){this.milliseconds=100*+x}],SS:[n,function(x){this.milliseconds=10*+x}],SSS:[/\d{3}/,function(x){this.milliseconds=+x}],s:[r,C("seconds")],ss:[r,C("seconds")],m:[r,C("minutes")],mm:[r,C("minutes")],H:[r,C("hours")],h:[r,C("hours")],HH:[r,C("hours")],hh:[r,C("hours")],D:[r,C("day")],DD:[n,C("day")],Do:[u,function(x){var k=p.ordinal,O=x.match(/\d+/);if(this.day=O[0],k)for(var L=1;L<=31;L+=1)k(L).replace(/\[|\]/g,"")===x&&(this.day=L)}],w:[r,C("week")],ww:[n,C("week")],M:[r,C("month")],MM:[n,C("month")],MMM:[u,function(x){var k=M("months"),O=(M("monthsShort")||k.map(function(L){return L.slice(0,3)})).indexOf(x)+1;if(O<1)throw new Error;this.month=O%12||O}],MMMM:[u,function(x){var k=M("months").indexOf(x)+1;if(k<1)throw new Error;this.month=k%12||k}],Y:[/[+-]?\d+/,C("year")],YY:[n,function(x){this.year=D(x)}],YYYY:[/\d{4}/,C("year")],Z:V,ZZ:V};function z(x){var k,O;k=x,O=p&&p.formats;for(var L=(x=k.replace(/(\[[^\]]+])|(LTS?|l{1,4}|L{1,4})/g,function(A,f,y){var g=y&&y.toUpperCase();return f||O[y]||t[y]||O[g].replace(/(\[[^\]]+])|(MMMM|MM|DD|dddd)/g,function(b,v,o){return v||o.slice(1)})})).match(i),X=L.length,U=0;U<X;U+=1){var I=L[U],T=H[I],d=T&&T[0],E=T&&T[1];L[U]=E?{regex:d,parser:E}:I.replace(/^\[|\]$/g,"")}return function(A){for(var f={},y=0,g=0;y<X;y+=1){var b=L[y];if(typeof b=="string")g+=b.length;else{var v=b.regex,o=b.parser,l=A.slice(g),h=v.exec(l)[0];o.call(f,h),A=A.replace(h,"")}}return function(m){var w=m.afternoon;if(w!==void 0){var s=m.hours;w?s<12&&(m.hours+=12):s===12&&(m.hours=0),delete m.afternoon}}(f),f}}return S(z,"l"),function(x,k,O){O.p.customParseFormat=!0,x&&x.parseTwoDigitYear&&(D=x.parseTwoDigitYear);var L=k.prototype,X=L.parse;L.parse=function(U){var I=U.date,T=U.utc,d=U.args;this.$u=T;var E=d[1];if(typeof E=="string"){var A=d[2]===!0,f=d[3]===!0,y=A||f,g=d[2];f&&(g=d[2]),p=this.$locale(),!A&&g&&(p=O.Ls[g]),this.$d=function(l,h,m,w){try{if(["x","X"].indexOf(h)>-1)return new Date((h==="X"?1e3:1)*l);var s=z(h)(l),W=s.year,e=s.month,_=s.day,F=s.hours,Y=s.minutes,$=s.seconds,B=s.milliseconds,N=s.zone,R=s.week,q=new Date,rt=_||(W||e?1:q.getDate()),nt=W||q.getFullYear(),ut=0;W&&!e||(ut=e>0?e-1:q.getMonth());var dt,ft=F||0,j=Y||0,at=$||0,J=B||0;return N?new Date(Date.UTC(nt,ut,rt,ft,j,at,J+60*N.offset*1e3)):m?new Date(Date.UTC(nt,ut,rt,ft,j,at,J)):(dt=new Date(nt,ut,rt,ft,j,at,J),R&&(dt=w(dt).week(R).toDate()),dt)}catch{return new Date("")}}(I,E,T,O),this.init(),g&&g!==!0&&(this.$L=this.locale(g).$L),y&&I!=this.format(E)&&(this.$d=new Date("")),p={}}else if(E instanceof Array)for(var b=E.length,v=1;v<=b;v+=1){d[1]=E[v-1];var o=O.apply(this,d);if(o.isValid()){this.$d=o.$d,this.$L=o.$L,this.init();break}v===b&&(this.$d=new Date(""))}else X.call(this,U)}}})});var Fe=_t((zt,Ht)=>{(function(t,i){typeof zt=="object"&&typeof Ht<"u"?Ht.exports=i():typeof define=="function"&&define.amd?define(i):(t=typeof globalThis<"u"?globalThis:t||self).dayjs_plugin_advancedFormat=i()})(zt,function(){"use strict";return function(t,i){var a=i.prototype,n=a.format;a.format=function(r){var u=this,p=this.$locale();if(!this.isValid())return n.bind(this)(r);var D=this.$utils(),C=(r||"YYYY-MM-DDTHH:mm:ssZ").replace(/\[([^\]]+)]|Q|wo|ww|w|WW|W|zzz|z|gggg|GGGG|Do|X|x|k{1,2}|S/g,function(V){switch(V){case"Q":return Math.ceil((u.$M+1)/3);case"Do":return p.ordinal(u.$D);case"gggg":return u.weekYear();case"GGGG":return u.isoWeekYear();case"wo":return p.ordinal(u.week(),"W");case"w":case"ww":return D.s(u.week(),V==="w"?1:2,"0");case"W":case"WW":return D.s(u.isoWeek(),V==="W"?1:2,"0");case"k":case"kk":return D.s(String(u.$H===0?24:u.$H),V==="k"?1:2,"0");case"X":return Math.floor(u.$d.getTime()/1e3);case"x":return u.$d.getTime();case"z":return"["+u.offsetName()+"]";case"zzz":return"["+u.offsetName("long")+"]";default:return V}});return n.bind(this)(C)}}})});var Oe=_t((Bt,jt)=>{(function(t,i){typeof Bt=="object"&&typeof jt<"u"?jt.exports=i():typeof define=="function"&&define.amd?define(i):(t=typeof globalThis<"u"?globalThis:t||self).dayjs_plugin_duration=i()})(Bt,function(){"use strict";var t,i,a=1e3,n=6e4,r=36e5,u=864e5,p=31536e6,D=2628e6,C=/^(-|\+)?P(?:([-+]?[0-9,.]*)Y)?(?:([-+]?[0-9,.]*)M)?(?:([-+]?[0-9,.]*)W)?(?:([-+]?[0-9,.]*)D)?(?:T(?:([-+]?[0-9,.]*)H)?(?:([-+]?[0-9,.]*)M)?(?:([-+]?[0-9,.]*)S)?)?$/,V=/\[([^\]]+)]|YYYY|YY|Y|M{1,2}|D{1,2}|H{1,2}|m{1,2}|s{1,2}|SSS/g,M={years:p,months:D,days:u,hours:r,minutes:n,seconds:a,milliseconds:1,weeks:6048e5},P=S(function(I){return I instanceof X},"c"),H=S(function(I,T,d){return new X(I,d,T.$l)},"f"),z=S(function(I){return i.p(I)+"s"},"m"),x=S(function(I){return I<0},"l"),k=S(function(I){return x(I)?Math.ceil(I):Math.floor(I)},"$"),O=S(function(I){return Math.abs(I)},"y"),L=S(function(I,T){return I?x(I)?{negative:!0,format:""+O(I)+T}:{negative:!1,format:""+I+T}:{negative:!1,format:""}},"v"),X=function(){function I(d,E,A){var f=this;if(this.$d={},this.$l=A,d===void 0&&(this.$ms=0,this.parseFromMilliseconds()),E)return H(d*M[z(E)],this);if(typeof d=="number")return this.$ms=d,this.parseFromMilliseconds(),this;if(typeof d=="object")return Object.keys(d).forEach(function(b){f.$d[z(b)]=d[b]}),this.calMilliseconds(),this;if(typeof d=="string"){var y=d.match(C);if(y){var g=y.slice(2).map(function(b){return b!=null?Number(b):0});return this.$d.years=g[0],this.$d.months=g[1],this.$d.weeks=g[2],this.$d.days=g[3],this.$d.hours=g[4],this.$d.minutes=g[5],this.$d.seconds=g[6],this.calMilliseconds(),this}}return this}S(I,"l");var T=I.prototype;return T.calMilliseconds=function(){var d=this;this.$ms=Object.keys(this.$d).reduce(function(E,A){return E+(d.$d[A]||0)*M[A]},0)},T.parseFromMilliseconds=function(){var d=this.$ms;this.$d.years=k(d/p),d%=p,this.$d.months=k(d/D),d%=D,this.$d.days=k(d/u),d%=u,this.$d.hours=k(d/r),d%=r,this.$d.minutes=k(d/n),d%=n,this.$d.seconds=k(d/a),d%=a,this.$d.milliseconds=d},T.toISOString=function(){var d=L(this.$d.years,"Y"),E=L(this.$d.months,"M"),A=+this.$d.days||0;this.$d.weeks&&(A+=7*this.$d.weeks);var f=L(A,"D"),y=L(this.$d.hours,"H"),g=L(this.$d.minutes,"M"),b=this.$d.seconds||0;this.$d.milliseconds&&(b+=this.$d.milliseconds/1e3,b=Math.round(1e3*b)/1e3);var v=L(b,"S"),o=d.negative||E.negative||f.negative||y.negative||g.negative||v.negative,l=y.format||g.format||v.format?"T":"",h=(o?"-":"")+"P"+d.format+E.format+f.format+l+y.format+g.format+v.format;return h==="P"||h==="-P"?"P0D":h},T.toJSON=function(){return this.toISOString()},T.format=function(d){var E=d||"YYYY-MM-DDTHH:mm:ss",A={Y:this.$d.years,YY:i.s(this.$d.years,2,"0"),YYYY:i.s(this.$d.years,4,"0"),M:this.$d.months,MM:i.s(this.$d.months,2,"0"),D:this.$d.days,DD:i.s(this.$d.days,2,"0"),H:this.$d.hours,HH:i.s(this.$d.hours,2,"0"),m:this.$d.minutes,mm:i.s(this.$d.minutes,2,"0"),s:this.$d.seconds,ss:i.s(this.$d.seconds,2,"0"),SSS:i.s(this.$d.milliseconds,3,"0")};return E.replace(V,function(f,y){return y||String(A[f])})},T.as=function(d){return this.$ms/M[z(d)]},T.get=function(d){var E=this.$ms,A=z(d);return A==="milliseconds"?E%=1e3:E=A==="weeks"?k(E/M[A]):this.$d[A],E||0},T.add=function(d,E,A){var f;return f=E?d*M[z(E)]:P(d)?d.$ms:H(d,this).$ms,H(this.$ms+f*(A?-1:1),this)},T.subtract=function(d,E){return this.add(d,E,!0)},T.locale=function(d){var E=this.clone();return E.$l=d,E},T.clone=function(){return H(this.$ms,this)},T.humanize=function(d){return t().add(this.$ms,"ms").locale(this.$l).fromNow(!d)},T.valueOf=function(){return this.asMilliseconds()},T.milliseconds=function(){return this.get("milliseconds")},T.asMilliseconds=function(){return this.as("milliseconds")},T.seconds=function(){return this.get("seconds")},T.asSeconds=function(){return this.as("seconds")},T.minutes=function(){return this.get("minutes")},T.asMinutes=function(){return this.as("minutes")},T.hours=function(){return this.get("hours")},T.asHours=function(){return this.as("hours")},T.days=function(){return this.get("days")},T.asDays=function(){return this.as("days")},T.weeks=function(){return this.get("weeks")},T.asWeeks=function(){return this.as("weeks")},T.months=function(){return this.get("months")},T.asMonths=function(){return this.as("months")},T.years=function(){return this.get("years")},T.asYears=function(){return this.as("years")},I}(),U=S(function(I,T,d){return I.add(T.years()*d,"y").add(T.months()*d,"M").add(T.days()*d,"d").add(T.hours()*d,"h").add(T.minutes()*d,"m").add(T.seconds()*d,"s").add(T.milliseconds()*d,"ms")},"p");return function(I,T,d){t=d,i=d().$utils(),d.duration=function(f,y){var g=d.locale();return H(f,{$l:g},y)},d.isDuration=P;var E=T.prototype.add,A=T.prototype.subtract;T.prototype.add=function(f,y){return P(f)?U(this,f,1):E.bind(this)(f,y)},T.prototype.subtract=function(f,y){return P(f)?U(this,f,-1):A.bind(this)(f,y)}}})});var Ne=ot(Je(),1),K=ot(ce(),1),Re=ot(Le(),1),ze=ot(Ae(),1),He=ot(Fe(),1),kt=ot(ce(),1),Ke=ot(Oe(),1);var Xt=function(){var t=c(function(v,o,l,h){for(l=l||{},h=v.length;h--;l[v[h]]=o);return l},"o"),i=[6,8,10,12,13,14,15,16,17,18,20,21,22,23,24,25,26,27,28,29,30,31,33,35,36,38,40],a=[1,26],n=[1,27],r=[1,28],u=[1,29],p=[1,30],D=[1,31],C=[1,32],V=[1,33],M=[1,34],P=[1,9],H=[1,10],z=[1,11],x=[1,12],k=[1,13],O=[1,14],L=[1,15],X=[1,16],U=[1,19],I=[1,20],T=[1,21],d=[1,22],E=[1,23],A=[1,25],f=[1,35],y={trace:c(S(function(){},"trace"),"trace"),yy:{},symbols_:{error:2,start:3,gantt:4,document:5,EOF:6,line:7,SPACE:8,statement:9,NL:10,weekday:11,weekday_monday:12,weekday_tuesday:13,weekday_wednesday:14,weekday_thursday:15,weekday_friday:16,weekday_saturday:17,weekday_sunday:18,weekend:19,weekend_friday:20,weekend_saturday:21,dateFormat:22,inclusiveEndDates:23,topAxis:24,axisFormat:25,tickInterval:26,excludes:27,includes:28,todayMarker:29,title:30,acc_title:31,acc_title_value:32,acc_descr:33,acc_descr_value:34,acc_descr_multiline_value:35,section:36,clickStatement:37,taskTxt:38,taskData:39,click:40,callbackname:41,callbackargs:42,href:43,clickStatementDebug:44,$accept:0,$end:1},terminals_:{2:"error",4:"gantt",6:"EOF",8:"SPACE",10:"NL",12:"weekday_monday",13:"weekday_tuesday",14:"weekday_wednesday",15:"weekday_thursday",16:"weekday_friday",17:"weekday_saturday",18:"weekday_sunday",20:"weekend_friday",21:"weekend_saturday",22:"dateFormat",23:"inclusiveEndDates",24:"topAxis",25:"axisFormat",26:"tickInterval",27:"excludes",28:"includes",29:"todayMarker",30:"title",31:"acc_title",32:"acc_title_value",33:"acc_descr",34:"acc_descr_value",35:"acc_descr_multiline_value",36:"section",38:"taskTxt",39:"taskData",40:"click",41:"callbackname",42:"callbackargs",43:"href"},productions_:[0,[3,3],[5,0],[5,2],[7,2],[7,1],[7,1],[7,1],[11,1],[11,1],[11,1],[11,1],[11,1],[11,1],[11,1],[19,1],[19,1],[9,1],[9,1],[9,1],[9,1],[9,1],[9,1],[9,1],[9,1],[9,1],[9,1],[9,1],[9,2],[9,2],[9,1],[9,1],[9,1],[9,2],[37,2],[37,3],[37,3],[37,4],[37,3],[37,4],[37,2],[44,2],[44,3],[44,3],[44,4],[44,3],[44,4],[44,2]],performAction:c(S(function(o,l,h,m,w,s,W){var e=s.length-1;switch(w){case 1:return s[e-1];case 2:this.$=[];break;case 3:s[e-1].push(s[e]),this.$=s[e-1];break;case 4:case 5:this.$=s[e];break;case 6:case 7:this.$=[];break;case 8:m.setWeekday("monday");break;case 9:m.setWeekday("tuesday");break;case 10:m.setWeekday("wednesday");break;case 11:m.setWeekday("thursday");break;case 12:m.setWeekday("friday");break;case 13:m.setWeekday("saturday");break;case 14:m.setWeekday("sunday");break;case 15:m.setWeekend("friday");break;case 16:m.setWeekend("saturday");break;case 17:m.setDateFormat(s[e].substr(11)),this.$=s[e].substr(11);break;case 18:m.enableInclusiveEndDates(),this.$=s[e].substr(18);break;case 19:m.TopAxis(),this.$=s[e].substr(8);break;case 20:m.setAxisFormat(s[e].substr(11)),this.$=s[e].substr(11);break;case 21:m.setTickInterval(s[e].substr(13)),this.$=s[e].substr(13);break;case 22:m.setExcludes(s[e].substr(9)),this.$=s[e].substr(9);break;case 23:m.setIncludes(s[e].substr(9)),this.$=s[e].substr(9);break;case 24:m.setTodayMarker(s[e].substr(12)),this.$=s[e].substr(12);break;case 27:m.setDiagramTitle(s[e].substr(6)),this.$=s[e].substr(6);break;case 28:this.$=s[e].trim(),m.setAccTitle(this.$);break;case 29:case 30:this.$=s[e].trim(),m.setAccDescription(this.$);break;case 31:m.addSection(s[e].substr(8)),this.$=s[e].substr(8);break;case 33:m.addTask(s[e-1],s[e]),this.$="task";break;case 34:this.$=s[e-1],m.setClickEvent(s[e-1],s[e],null);break;case 35:this.$=s[e-2],m.setClickEvent(s[e-2],s[e-1],s[e]);break;case 36:this.$=s[e-2],m.setClickEvent(s[e-2],s[e-1],null),m.setLink(s[e-2],s[e]);break;case 37:this.$=s[e-3],m.setClickEvent(s[e-3],s[e-2],s[e-1]),m.setLink(s[e-3],s[e]);break;case 38:this.$=s[e-2],m.setClickEvent(s[e-2],s[e],null),m.setLink(s[e-2],s[e-1]);break;case 39:this.$=s[e-3],m.setClickEvent(s[e-3],s[e-1],s[e]),m.setLink(s[e-3],s[e-2]);break;case 40:this.$=s[e-1],m.setLink(s[e-1],s[e]);break;case 41:case 47:this.$=s[e-1]+" "+s[e];break;case 42:case 43:case 45:this.$=s[e-2]+" "+s[e-1]+" "+s[e];break;case 44:case 46:this.$=s[e-3]+" "+s[e-2]+" "+s[e-1]+" "+s[e];break}},"anonymous"),"anonymous"),table:[{3:1,4:[1,2]},{1:[3]},t(i,[2,2],{5:3}),{6:[1,4],7:5,8:[1,6],9:7,10:[1,8],11:17,12:a,13:n,14:r,15:u,16:p,17:D,18:C,19:18,20:V,21:M,22:P,23:H,24:z,25:x,26:k,27:O,28:L,29:X,30:U,31:I,33:T,35:d,36:E,37:24,38:A,40:f},t(i,[2,7],{1:[2,1]}),t(i,[2,3]),{9:36,11:17,12:a,13:n,14:r,15:u,16:p,17:D,18:C,19:18,20:V,21:M,22:P,23:H,24:z,25:x,26:k,27:O,28:L,29:X,30:U,31:I,33:T,35:d,36:E,37:24,38:A,40:f},t(i,[2,5]),t(i,[2,6]),t(i,[2,17]),t(i,[2,18]),t(i,[2,19]),t(i,[2,20]),t(i,[2,21]),t(i,[2,22]),t(i,[2,23]),t(i,[2,24]),t(i,[2,25]),t(i,[2,26]),t(i,[2,27]),{32:[1,37]},{34:[1,38]},t(i,[2,30]),t(i,[2,31]),t(i,[2,32]),{39:[1,39]},t(i,[2,8]),t(i,[2,9]),t(i,[2,10]),t(i,[2,11]),t(i,[2,12]),t(i,[2,13]),t(i,[2,14]),t(i,[2,15]),t(i,[2,16]),{41:[1,40],43:[1,41]},t(i,[2,4]),t(i,[2,28]),t(i,[2,29]),t(i,[2,33]),t(i,[2,34],{42:[1,42],43:[1,43]}),t(i,[2,40],{41:[1,44]}),t(i,[2,35],{43:[1,45]}),t(i,[2,36]),t(i,[2,38],{42:[1,46]}),t(i,[2,37]),t(i,[2,39])],defaultActions:{},parseError:c(S(function(o,l){if(l.recoverable)this.trace(o);else{var h=new Error(o);throw h.hash=l,h}},"parseError"),"parseError"),parse:c(S(function(o){var l=this,h=[0],m=[],w=[null],s=[],W=this.table,e="",_=0,F=0,Y=0,$=2,B=1,N=s.slice.call(arguments,1),R=Object.create(this.lexer),q={yy:{}};for(var rt in this.yy)Object.prototype.hasOwnProperty.call(this.yy,rt)&&(q.yy[rt]=this.yy[rt]);R.setInput(o,q.yy),q.yy.lexer=R,q.yy.parser=this,typeof R.yylloc>"u"&&(R.yylloc={});var nt=R.yylloc;s.push(nt);var ut=R.options&&R.options.ranges;typeof q.yy.parseError=="function"?this.parseError=q.yy.parseError:this.parseError=Object.getPrototypeOf(this).parseError;function dt(Q){h.length=h.length-2*Q,w.length=w.length-Q,s.length=s.length-Q}S(dt,"popStack"),c(dt,"popStack");function ft(){var Q;return Q=m.pop()||R.lex()||B,typeof Q!="number"&&(Q instanceof Array&&(m=Q,Q=m.pop()),Q=l.symbols_[Q]||Q),Q}S(ft,"lex"),c(ft,"lex");for(var j,at,J,Z,Bi,Et,ht={},xt,et,oe,wt;;){if(J=h[h.length-1],this.defaultActions[J]?Z=this.defaultActions[J]:((j===null||typeof j>"u")&&(j=ft()),Z=W[J]&&W[J][j]),typeof Z>"u"||!Z.length||!Z[0]){var It="";wt=[];for(xt in W[J])this.terminals_[xt]&&xt>$&&wt.push("'"+this.terminals_[xt]+"'");R.showPosition?It="Parse error on line "+(_+1)+`:
`+R.showPosition()+`
Expecting `+wt.join(", ")+", got '"+(this.terminals_[j]||j)+"'":It="Parse error on line "+(_+1)+": Unexpected "+(j==B?"end of input":"'"+(this.terminals_[j]||j)+"'"),this.parseError(It,{text:R.match,token:this.terminals_[j]||j,line:R.yylineno,loc:nt,expected:wt})}if(Z[0]instanceof Array&&Z.length>1)throw new Error("Parse Error: multiple actions possible at state: "+J+", token: "+j);switch(Z[0]){case 1:h.push(j),w.push(R.yytext),s.push(R.yylloc),h.push(Z[1]),j=null,at?(j=at,at=null):(F=R.yyleng,e=R.yytext,_=R.yylineno,nt=R.yylloc,Y>0&&Y--);break;case 2:if(et=this.productions_[Z[1]][1],ht.$=w[w.length-et],ht._$={first_line:s[s.length-(et||1)].first_line,last_line:s[s.length-1].last_line,first_column:s[s.length-(et||1)].first_column,last_column:s[s.length-1].last_column},ut&&(ht._$.range=[s[s.length-(et||1)].range[0],s[s.length-1].range[1]]),Et=this.performAction.apply(ht,[e,F,_,q.yy,Z[1],w,s].concat(N)),typeof Et<"u")return Et;et&&(h=h.slice(0,-1*et*2),w=w.slice(0,-1*et),s=s.slice(0,-1*et)),h.push(this.productions_[Z[1]][0]),w.push(ht.$),s.push(ht._$),oe=W[h[h.length-2]][h[h.length-1]],h.push(oe);break;case 3:return!0}}return!0},"parse"),"parse")},g=function(){var v={EOF:1,parseError:c(S(function(l,h){if(this.yy.parser)this.yy.parser.parseError(l,h);else throw new Error(l)},"parseError"),"parseError"),setInput:c(function(o,l){return this.yy=l||this.yy||{},this._input=o,this._more=this._backtrack=this.done=!1,this.yylineno=this.yyleng=0,this.yytext=this.matched=this.match="",this.conditionStack=["INITIAL"],this.yylloc={first_line:1,first_column:0,last_line:1,last_column:0},this.options.ranges&&(this.yylloc.range=[0,0]),this.offset=0,this},"setInput"),input:c(function(){var o=this._input[0];this.yytext+=o,this.yyleng++,this.offset++,this.match+=o,this.matched+=o;var l=o.match(/(?:\r\n?|\n).*/g);return l?(this.yylineno++,this.yylloc.last_line++):this.yylloc.last_column++,this.options.ranges&&this.yylloc.range[1]++,this._input=this._input.slice(1),o},"input"),unput:c(function(o){var l=o.length,h=o.split(/(?:\r\n?|\n)/g);this._input=o+this._input,this.yytext=this.yytext.substr(0,this.yytext.length-l),this.offset-=l;var m=this.match.split(/(?:\r\n?|\n)/g);this.match=this.match.substr(0,this.match.length-1),this.matched=this.matched.substr(0,this.matched.length-1),h.length-1&&(this.yylineno-=h.length-1);var w=this.yylloc.range;return this.yylloc={first_line:this.yylloc.first_line,last_line:this.yylineno+1,first_column:this.yylloc.first_column,last_column:h?(h.length===m.length?this.yylloc.first_column:0)+m[m.length-h.length].length-h[0].length:this.yylloc.first_column-l},this.options.ranges&&(this.yylloc.range=[w[0],w[0]+this.yyleng-l]),this.yyleng=this.yytext.length,this},"unput"),more:c(function(){return this._more=!0,this},"more"),reject:c(function(){if(this.options.backtrack_lexer)this._backtrack=!0;else return this.parseError("Lexical error on line "+(this.yylineno+1)+`. You can only invoke reject() in the lexer when the lexer is of the backtracking persuasion (options.backtrack_lexer = true).
`+this.showPosition(),{text:"",token:null,line:this.yylineno});return this},"reject"),less:c(function(o){this.unput(this.match.slice(o))},"less"),pastInput:c(function(){var o=this.matched.substr(0,this.matched.length-this.match.length);return(o.length>20?"...":"")+o.substr(-20).replace(/\n/g,"")},"pastInput"),upcomingInput:c(function(){var o=this.match;return o.length<20&&(o+=this._input.substr(0,20-o.length)),(o.substr(0,20)+(o.length>20?"...":"")).replace(/\n/g,"")},"upcomingInput"),showPosition:c(function(){var o=this.pastInput(),l=new Array(o.length+1).join("-");return o+this.upcomingInput()+`
`+l+"^"},"showPosition"),test_match:c(function(o,l){var h,m,w;if(this.options.backtrack_lexer&&(w={yylineno:this.yylineno,yylloc:{first_line:this.yylloc.first_line,last_line:this.last_line,first_column:this.yylloc.first_column,last_column:this.yylloc.last_column},yytext:this.yytext,match:this.match,matches:this.matches,matched:this.matched,yyleng:this.yyleng,offset:this.offset,_more:this._more,_input:this._input,yy:this.yy,conditionStack:this.conditionStack.slice(0),done:this.done},this.options.ranges&&(w.yylloc.range=this.yylloc.range.slice(0))),m=o[0].match(/(?:\r\n?|\n).*/g),m&&(this.yylineno+=m.length),this.yylloc={first_line:this.yylloc.last_line,last_line:this.yylineno+1,first_column:this.yylloc.last_column,last_column:m?m[m.length-1].length-m[m.length-1].match(/\r?\n?/)[0].length:this.yylloc.last_column+o[0].length},this.yytext+=o[0],this.match+=o[0],this.matches=o,this.yyleng=this.yytext.length,this.options.ranges&&(this.yylloc.range=[this.offset,this.offset+=this.yyleng]),this._more=!1,this._backtrack=!1,this._input=this._input.slice(o[0].length),this.matched+=o[0],h=this.performAction.call(this,this.yy,this,l,this.conditionStack[this.conditionStack.length-1]),this.done&&this._input&&(this.done=!1),h)return h;if(this._backtrack){for(var s in w)this[s]=w[s];return!1}return!1},"test_match"),next:c(function(){if(this.done)return this.EOF;this._input||(this.done=!0);var o,l,h,m;this._more||(this.yytext="",this.match="");for(var w=this._currentRules(),s=0;s<w.length;s++)if(h=this._input.match(this.rules[w[s]]),h&&(!l||h[0].length>l[0].length)){if(l=h,m=s,this.options.backtrack_lexer){if(o=this.test_match(h,w[s]),o!==!1)return o;if(this._backtrack){l=!1;continue}else return!1}else if(!this.options.flex)break}return l?(o=this.test_match(l,w[m]),o!==!1?o:!1):this._input===""?this.EOF:this.parseError("Lexical error on line "+(this.yylineno+1)+`. Unrecognized text.
`+this.showPosition(),{text:"",token:null,line:this.yylineno})},"next"),lex:c(S(function(){var l=this.next();return l||this.lex()},"lex"),"lex"),begin:c(S(function(l){this.conditionStack.push(l)},"begin"),"begin"),popState:c(S(function(){var l=this.conditionStack.length-1;return l>0?this.conditionStack.pop():this.conditionStack[0]},"popState"),"popState"),_currentRules:c(S(function(){return this.conditionStack.length&&this.conditionStack[this.conditionStack.length-1]?this.conditions[this.conditionStack[this.conditionStack.length-1]].rules:this.conditions.INITIAL.rules},"_currentRules"),"_currentRules"),topState:c(S(function(l){return l=this.conditionStack.length-1-Math.abs(l||0),l>=0?this.conditionStack[l]:"INITIAL"},"topState"),"topState"),pushState:c(S(function(l){this.begin(l)},"pushState"),"pushState"),stateStackSize:c(S(function(){return this.conditionStack.length},"stateStackSize"),"stateStackSize"),options:{"case-insensitive":!0},performAction:c(S(function(l,h,m,w){var s=w;switch(m){case 0:return this.begin("open_directive"),"open_directive";break;case 1:return this.begin("acc_title"),31;break;case 2:return this.popState(),"acc_title_value";break;case 3:return this.begin("acc_descr"),33;break;case 4:return this.popState(),"acc_descr_value";break;case 5:this.begin("acc_descr_multiline");break;case 6:this.popState();break;case 7:return"acc_descr_multiline_value";case 8:break;case 9:break;case 10:break;case 11:return 10;case 12:break;case 13:break;case 14:this.begin("href");break;case 15:this.popState();break;case 16:return 43;case 17:this.begin("callbackname");break;case 18:this.popState();break;case 19:this.popState(),this.begin("callbackargs");break;case 20:return 41;case 21:this.popState();break;case 22:return 42;case 23:this.begin("click");break;case 24:this.popState();break;case 25:return 40;case 26:return 4;case 27:return 22;case 28:return 23;case 29:return 24;case 30:return 25;case 31:return 26;case 32:return 28;case 33:return 27;case 34:return 29;case 35:return 12;case 36:return 13;case 37:return 14;case 38:return 15;case 39:return 16;case 40:return 17;case 41:return 18;case 42:return 20;case 43:return 21;case 44:return"date";case 45:return 30;case 46:return"accDescription";case 47:return 36;case 48:return 38;case 49:return 39;case 50:return":";case 51:return 6;case 52:return"INVALID"}},"anonymous"),"anonymous"),rules:[/^(?:%%\{)/i,/^(?:accTitle\s*:\s*)/i,/^(?:(?!\n||)*[^\n]*)/i,/^(?:accDescr\s*:\s*)/i,/^(?:(?!\n||)*[^\n]*)/i,/^(?:accDescr\s*\{\s*)/i,/^(?:[\}])/i,/^(?:[^\}]*)/i,/^(?:%%(?!\{)*[^\n]*)/i,/^(?:[^\}]%%*[^\n]*)/i,/^(?:%%*[^\n]*[\n]*)/i,/^(?:[\n]+)/i,/^(?:\s+)/i,/^(?:%[^\n]*)/i,/^(?:href[\s]+["])/i,/^(?:["])/i,/^(?:[^"]*)/i,/^(?:call[\s]+)/i,/^(?:\([\s]*\))/i,/^(?:\()/i,/^(?:[^(]*)/i,/^(?:\))/i,/^(?:[^)]*)/i,/^(?:click[\s]+)/i,/^(?:[\s\n])/i,/^(?:[^\s\n]*)/i,/^(?:gantt\b)/i,/^(?:dateFormat\s[^#\n;]+)/i,/^(?:inclusiveEndDates\b)/i,/^(?:topAxis\b)/i,/^(?:axisFormat\s[^#\n;]+)/i,/^(?:tickInterval\s[^#\n;]+)/i,/^(?:includes\s[^#\n;]+)/i,/^(?:excludes\s[^#\n;]+)/i,/^(?:todayMarker\s[^\n;]+)/i,/^(?:weekday\s+monday\b)/i,/^(?:weekday\s+tuesday\b)/i,/^(?:weekday\s+wednesday\b)/i,/^(?:weekday\s+thursday\b)/i,/^(?:weekday\s+friday\b)/i,/^(?:weekday\s+saturday\b)/i,/^(?:weekday\s+sunday\b)/i,/^(?:weekend\s+friday\b)/i,/^(?:weekend\s+saturday\b)/i,/^(?:\d\d\d\d-\d\d-\d\d\b)/i,/^(?:title\s[^\n]+)/i,/^(?:accDescription\s[^#\n;]+)/i,/^(?:section\s[^\n]+)/i,/^(?:[^:\n]+)/i,/^(?::[^#\n;]+)/i,/^(?::)/i,/^(?:$)/i,/^(?:.)/i],conditions:{acc_descr_multiline:{rules:[6,7],inclusive:!1},acc_descr:{rules:[4],inclusive:!1},acc_title:{rules:[2],inclusive:!1},callbackargs:{rules:[21,22],inclusive:!1},callbackname:{rules:[18,19,20],inclusive:!1},href:{rules:[15,16],inclusive:!1},click:{rules:[24,25],inclusive:!1},INITIAL:{rules:[0,1,3,5,8,9,10,11,12,13,14,17,23,26,27,28,29,30,31,32,33,34,35,36,37,38,39,40,41,42,43,44,45,46,47,48,49,50,51,52],inclusive:!0}}};return v}();y.lexer=g;function b(){this.yy={}}return S(b,"Parser"),c(b,"Parser"),b.prototype=y,y.Parser=b,new b}();Xt.parser=Xt;var ti=Xt;K.default.extend(Re.default);K.default.extend(ze.default);K.default.extend(He.default);var We={friday:5,saturday:6},tt="",Qt="",Kt=void 0,Jt="",vt=[],Tt=[],te=new Map,ee=[],Ct=[],gt="",ie="",Be=["active","done","crit","milestone","vert"],se=[],mt="",bt=!1,re=!1,ne="sunday",Mt="saturday",Ut=0,ei=c(function(){ee=[],Ct=[],gt="",se=[],Dt=0,Zt=void 0,St=void 0,G=[],tt="",Qt="",ie="",Kt=void 0,Jt="",vt=[],Tt=[],bt=!1,re=!1,Ut=0,te=new Map,mt="",de(),ne="sunday",Mt="saturday"},"clear"),ii=c(function(t){mt=t},"setDiagramId"),si=c(function(t){Qt=t},"setAxisFormat"),ri=c(function(){return Qt},"getAxisFormat"),ni=c(function(t){Kt=t},"setTickInterval"),ai=c(function(){return Kt},"getTickInterval"),oi=c(function(t){Jt=t},"setTodayMarker"),ci=c(function(){return Jt},"getTodayMarker"),li=c(function(t){tt=t},"setDateFormat"),ui=c(function(){bt=!0},"enableInclusiveEndDates"),di=c(function(){return bt},"endDatesAreInclusive"),fi=c(function(){re=!0},"enableTopAxis"),hi=c(function(){return re},"topAxisEnabled"),mi=c(function(t){ie=t},"setDisplayMode"),ki=c(function(){return ie},"getDisplayMode"),yi=c(function(){return tt},"getDateFormat"),gi=c(function(t){vt=t.toLowerCase().split(/[\s,]+/)},"setIncludes"),pi=c(function(){return vt},"getIncludes"),vi=c(function(t){Tt=t.toLowerCase().split(/[\s,]+/)},"setExcludes"),Ti=c(function(){return Tt},"getExcludes"),bi=c(function(){return te},"getLinks"),xi=c(function(t){gt=t,ee.push(t)},"addSection"),wi=c(function(){return ee},"getSections"),_i=c(function(){let t=Pe(),i=10,a=0;for(;!t&&a<i;)t=Pe(),a++;return Ct=G,Ct},"getTasks"),je=c(function(t,i,a,n){let r=t.format(i.trim()),u=t.format("YYYY-MM-DD");return n.includes(r)||n.includes(u)?!1:a.includes("weekends")&&(t.isoWeekday()===We[Mt]||t.isoWeekday()===We[Mt]+1)||a.includes(t.format("dddd").toLowerCase())?!0:a.includes(r)||a.includes(u)},"isInvalidDate"),Di=c(function(t){ne=t},"setWeekday"),Si=c(function(){return ne},"getWeekday"),Ci=c(function(t){Mt=t},"setWeekend"),Ge=c(function(t,i,a,n){if(!a.length||t.manualEndTime)return;let r;t.startTime instanceof Date?r=(0,K.default)(t.startTime):r=(0,K.default)(t.startTime,i,!0),r=r.add(1,"d");let u;t.endTime instanceof Date?u=(0,K.default)(t.endTime):u=(0,K.default)(t.endTime,i,!0);let[p,D]=Mi(r,u,i,a,n);t.endTime=p.toDate(),t.renderEndTime=D},"checkTaskDates"),Mi=c(function(t,i,a,n,r){let u=!1,p=null,D=i.add(1e4,"d");for(;t<=i;){if(u||(p=i.toDate()),u=je(t,a,n,r),u&&(i=i.add(1,"d"),i>D))throw new Error("Failed to find a valid date that was not excluded by `excludes` after 10,000 iterations.");t=t.add(1,"d")}return[i,p]},"fixTaskDates"),qt=c(function(t,i,a){if(a=a.trim(),c(D=>{let C=D.trim();return C==="x"||C==="X"},"isTimestampFormat")(i)&&/^\d+$/.test(a))return new Date(Number(a));let u=/^after\s+(?<ids>[\d\w- ]+)/.exec(a);if(u!==null){let D=null;for(let V of u.groups.ids.split(" ")){let M=lt(V);M!==void 0&&(!D||M.endTime>D.endTime)&&(D=M)}if(D)return D.endTime;let C=new Date;return C.setHours(0,0,0,0),C}let p=(0,K.default)(a,i.trim(),!0);if(p.isValid())return p.toDate();{st.debug("Invalid date:"+a),st.debug("With date format:"+i.trim());let D=new Date(a);if(D===void 0||isNaN(D.getTime())||D.getFullYear()<-1e4||D.getFullYear()>1e4)throw new Error("Invalid date:"+a);return D}},"getStartDate"),Xe=c(function(t){let i=/^(\d+(?:\.\d+)?)([Mdhmswy]|ms)$/.exec(t.trim());return i!==null?[Number.parseFloat(i[1]),i[2]]:[NaN,"ms"]},"parseDuration"),Ue=c(function(t,i,a,n=!1){a=a.trim();let u=/^until\s+(?<ids>[\d\w- ]+)/.exec(a);if(u!==null){let M=null;for(let H of u.groups.ids.split(" ")){let z=lt(H);z!==void 0&&(!M||z.startTime<M.startTime)&&(M=z)}if(M)return M.startTime;let P=new Date;return P.setHours(0,0,0,0),P}let p=(0,K.default)(a,i.trim(),!0);if(p.isValid())return n&&(p=p.add(1,"d")),p.toDate();let D=(0,K.default)(t),[C,V]=Xe(a);if(!Number.isNaN(C)){let M=D.add(C,V);M.isValid()&&(D=M)}return D.toDate()},"getEndDate"),Dt=0,yt=c(function(t){return t===void 0?(Dt=Dt+1,"task"+Dt):t},"parseId"),Ei=c(function(t,i){let a;i.substr(0,1)===":"?a=i.substr(1,i.length):a=i;let n=a.split(","),r={};ae(n,r,Be);for(let p=0;p<n.length;p++)n[p]=n[p].trim();let u="";switch(n.length){case 1:r.id=yt(),r.startTime=t.endTime,u=n[0];break;case 2:r.id=yt(),r.startTime=qt(void 0,tt,n[0]),u=n[1];break;case 3:r.id=yt(n[0]),r.startTime=qt(void 0,tt,n[1]),u=n[2];break;default:}return u&&(r.endTime=Ue(r.startTime,tt,u,bt),r.manualEndTime=(0,K.default)(u,"YYYY-MM-DD",!0).isValid(),Ge(r,tt,Tt,vt)),r},"compileData"),Ii=c(function(t,i){let a;i.substr(0,1)===":"?a=i.substr(1,i.length):a=i;let n=a.split(","),r={};ae(n,r,Be);for(let u=0;u<n.length;u++)n[u]=n[u].trim();switch(n.length){case 1:r.id=yt(),r.startTime={type:"prevTaskEnd",id:t},r.endTime={data:n[0]};break;case 2:r.id=yt(),r.startTime={type:"getStartDate",startData:n[0]},r.endTime={data:n[1]};break;case 3:r.id=yt(n[0]),r.startTime={type:"getStartDate",startData:n[1]},r.endTime={data:n[2]};break;default:}return r},"parseData"),Zt,St,G=[],qe={},Yi=c(function(t,i){let a={section:gt,type:gt,processed:!1,manualEndTime:!1,renderEndTime:null,raw:{data:i},task:t,classes:[]},n=Ii(St,i);a.raw.startTime=n.startTime,a.raw.endTime=n.endTime,a.id=n.id,a.prevTaskId=St,a.active=n.active,a.done=n.done,a.crit=n.crit,a.milestone=n.milestone,a.vert=n.vert,a.order=Ut,Ut++;let r=G.push(a);St=a.id,qe[a.id]=r-1},"addTask"),lt=c(function(t){let i=qe[t];return G[i]},"findTaskById"),$i=c(function(t,i){let a={section:gt,type:gt,description:t,task:t,classes:[]},n=Ei(Zt,i);a.startTime=n.startTime,a.endTime=n.endTime,a.id=n.id,a.active=n.active,a.done=n.done,a.crit=n.crit,a.milestone=n.milestone,a.vert=n.vert,Zt=a,Ct.push(a)},"addTaskOrg"),Pe=c(function(){let t=c(function(a){let n=G[a],r="";switch(G[a].raw.startTime.type){case"prevTaskEnd":{let u=lt(n.prevTaskId);n.startTime=u.endTime;break}case"getStartDate":r=qt(void 0,tt,G[a].raw.startTime.startData),r&&(G[a].startTime=r);break}return G[a].startTime&&(G[a].endTime=Ue(G[a].startTime,tt,G[a].raw.endTime.data,bt),G[a].endTime&&(G[a].processed=!0,G[a].manualEndTime=(0,K.default)(G[a].raw.endTime.data,"YYYY-MM-DD",!0).isValid(),Ge(G[a],tt,Tt,vt))),G[a].processed},"compileTask"),i=!0;for(let[a,n]of G.entries())t(a),i=i&&n.processed;return i},"compileTasks"),Li=c(function(t,i){let a=i;ct().securityLevel!=="loose"&&(a=(0,Ne.sanitizeUrl)(i)),t.split(",").forEach(function(n){lt(n)!==void 0&&(Qe(n,()=>{window.open(a,"_self")}),te.set(n,a))}),Ze(t,"clickable")},"setLink"),Ze=c(function(t,i){t.split(",").forEach(function(a){let n=lt(a);n!==void 0&&n.classes.push(i)})},"setClass"),Ai=c(function(t,i,a){if(ct().securityLevel!=="loose"||i===void 0)return;let n=[];if(typeof a=="string"){n=a.split(/,(?=(?:(?:[^"]*"){2})*[^"]*$)/);for(let u=0;u<n.length;u++){let p=n[u].trim();p.startsWith('"')&&p.endsWith('"')&&(p=p.substr(1,p.length-2)),n[u]=p}}n.length===0&&n.push(t),lt(t)!==void 0&&Qe(t,()=>{$e.runFunc(i,...n)})},"setClickFun"),Qe=c(function(t,i){se.push(function(){let a=mt?`${mt}-${t}`:t,n=document.querySelector(`[id="${a}"]`);n!==null&&n.addEventListener("click",function(){i()})},function(){let a=mt?`${mt}-${t}`:t,n=document.querySelector(`[id="${a}-text"]`);n!==null&&n.addEventListener("click",function(){i()})})},"pushFun"),Fi=c(function(t,i,a){t.split(",").forEach(function(n){Ai(n,i,a)}),Ze(t,"clickable")},"setClickEvent"),Oi=c(function(t){se.forEach(function(i){i(t)})},"bindFunctions"),Wi={getConfig:c(()=>ct().gantt,"getConfig"),clear:ei,setDateFormat:li,getDateFormat:yi,enableInclusiveEndDates:ui,endDatesAreInclusive:di,enableTopAxis:fi,topAxisEnabled:hi,setAxisFormat:si,getAxisFormat:ri,setTickInterval:ni,getTickInterval:ai,setTodayMarker:oi,getTodayMarker:ci,setAccTitle:fe,getAccTitle:he,setDiagramTitle:ye,getDiagramTitle:ge,setDiagramId:ii,setDisplayMode:mi,getDisplayMode:ki,setAccDescription:me,getAccDescription:ke,addSection:xi,getSections:wi,getTasks:_i,addTask:Yi,findTaskById:lt,addTaskOrg:$i,setIncludes:gi,getIncludes:pi,setExcludes:vi,getExcludes:Ti,setClickEvent:Fi,setLink:Li,getLinks:bi,bindFunctions:Oi,parseDuration:Xe,isInvalidDate:je,setWeekday:Di,getWeekday:Si,setWeekend:Ci};function ae(t,i,a){let n=!0;for(;n;)n=!1,a.forEach(function(r){let u="^\\s*"+r+"\\s*$",p=new RegExp(u);t[0].match(p)&&(i[r]=!0,t.shift(1),n=!0)})}S(ae,"getTaskTags");c(ae,"getTaskTags");kt.default.extend(Ke.default);var Pi=c(function(){st.debug("Something is calling, setConf, remove the call")},"setConf"),Ve={monday:De,tuesday:Se,wednesday:Ce,thursday:Me,friday:Ee,saturday:Ie,sunday:_e},Vi=c((t,i)=>{let a=[...t].map(()=>-1/0),n=[...t].sort((u,p)=>u.startTime-p.startTime||u.order-p.order),r=0;for(let u of n)for(let p=0;p<a.length;p++)if(u.startTime>=a[p]){a[p]=u.endTime,u.order=p+i,p>r&&(r=p);break}return r},"getMaxIntersections"),it,Gt=1e4,Ni=c(function(t,i,a,n){let r=ct().gantt;n.db.setDiagramId(i);let u=ct().securityLevel,p;u==="sandbox"&&(p=pt("#i"+i));let D=u==="sandbox"?pt(p.nodes()[0].contentDocument.body):pt("body"),C=u==="sandbox"?p.nodes()[0].contentDocument:document,V=C.getElementById(i);it=V.parentElement.offsetWidth,it===void 0&&(it=1200),r.useWidth!==void 0&&(it=r.useWidth);let M=n.db.getTasks(),P=[];for(let f of M)P.push(f.type);P=A(P);let H={},z=2*r.topPadding;if(n.db.getDisplayMode()==="compact"||r.displayMode==="compact"){let f={};for(let g of M)f[g.section]===void 0?f[g.section]=[g]:f[g.section].push(g);let y=0;for(let g of Object.keys(f)){let b=Vi(f[g],y)+1;y+=b,z+=b*(r.barHeight+r.barGap),H[g]=b}}else{z+=M.length*(r.barHeight+r.barGap);for(let f of P)H[f]=M.filter(y=>y.type===f).length}V.setAttribute("viewBox","0 0 "+it+" "+z);let x=D.select(`[id="${i}"]`),k=Ye().domain([ve(M,function(f){return f.startTime}),pe(M,function(f){return f.endTime})]).rangeRound([0,it-r.leftPadding-r.rightPadding]);function O(f,y){let g=f.startTime,b=y.startTime,v=0;return g>b?v=1:g<b&&(v=-1),v}S(O,"taskCompare"),c(O,"taskCompare"),M.sort(O),L(M,it,z),ue(x,z,it,r.useMaxWidth),x.append("text").text(n.db.getDiagramTitle()).attr("x",it/2).attr("y",r.titleTopMargin).attr("class","titleText");function L(f,y,g){let b=r.barHeight,v=b+r.barGap,o=r.topPadding,l=r.leftPadding,h=we().domain([0,P.length]).range(["#00B9FA","#F95002"]).interpolate(xe);U(v,o,l,y,g,f,n.db.getExcludes(),n.db.getIncludes()),T(l,o,y,g),X(f,v,o,l,b,h,y,g),d(v,o,l,b,h),E(l,o,y,g)}S(L,"makeGantt"),c(L,"makeGantt");function X(f,y,g,b,v,o,l){f.sort((e,_)=>e.vert===_.vert?0:e.vert?1:-1);let m=[...new Set(f.map(e=>e.order))].map(e=>f.find(_=>_.order===e));x.append("g").selectAll("rect").data(m).enter().append("rect").attr("x",0).attr("y",function(e,_){return _=e.order,_*y+g-2}).attr("width",function(){return l-r.rightPadding/2}).attr("height",y).attr("class",function(e){for(let[_,F]of P.entries())if(e.type===F)return"section section"+_%r.numberSectionStyles;return"section section0"}).enter();let w=x.append("g").selectAll("rect").data(f).enter(),s=n.db.getLinks();if(w.append("rect").attr("id",function(e){return i+"-"+e.id}).attr("rx",3).attr("ry",3).attr("x",function(e){return e.milestone?k(e.startTime)+b+.5*(k(e.endTime)-k(e.startTime))-.5*v:k(e.startTime)+b}).attr("y",function(e,_){return _=e.order,e.vert?r.gridLineStartPadding:_*y+g}).attr("width",function(e){return e.milestone?v:e.vert?.08*v:k(e.renderEndTime||e.endTime)-k(e.startTime)}).attr("height",function(e){return e.vert?M.length*(r.barHeight+r.barGap)+r.barHeight*2:v}).attr("transform-origin",function(e,_){return _=e.order,(k(e.startTime)+b+.5*(k(e.endTime)-k(e.startTime))).toString()+"px "+(_*y+g+.5*v).toString()+"px"}).attr("class",function(e){let _="task",F="";e.classes.length>0&&(F=e.classes.join(" "));let Y=0;for(let[B,N]of P.entries())e.type===N&&(Y=B%r.numberSectionStyles);let $="";return e.active?e.crit?$+=" activeCrit":$=" active":e.done?e.crit?$=" doneCrit":$=" done":e.crit&&($+=" crit"),$.length===0&&($=" task"),e.milestone&&($=" milestone "+$),e.vert&&($=" vert "+$),$+=Y,$+=" "+F,_+$}),w.append("text").attr("id",function(e){return i+"-"+e.id+"-text"}).text(function(e){return e.task}).attr("font-size",r.fontSize).attr("x",function(e){let _=k(e.startTime),F=k(e.renderEndTime||e.endTime);if(e.milestone&&(_+=.5*(k(e.endTime)-k(e.startTime))-.5*v,F=_+v),e.vert)return k(e.startTime)+b;let Y=this.getBBox().width;return Y>F-_?F+Y+1.5*r.leftPadding>l?_+b-5:F+b+5:(F-_)/2+_+b}).attr("y",function(e,_){return e.vert?r.gridLineStartPadding+M.length*(r.barHeight+r.barGap)+60:(_=e.order,_*y+r.barHeight/2+(r.fontSize/2-2)+g)}).attr("text-height",v).attr("class",function(e){let _=k(e.startTime),F=k(e.endTime);e.milestone&&(F=_+v);let Y=this.getBBox().width,$="";e.classes.length>0&&($=e.classes.join(" "));let B=0;for(let[R,q]of P.entries())e.type===q&&(B=R%r.numberSectionStyles);let N="";return e.active&&(e.crit?N="activeCritText"+B:N="activeText"+B),e.done?e.crit?N=N+" doneCritText"+B:N=N+" doneText"+B:e.crit&&(N=N+" critText"+B),e.milestone&&(N+=" milestoneText"),e.vert&&(N+=" vertText"),Y>F-_?F+Y+1.5*r.leftPadding>l?$+" taskTextOutsideLeft taskTextOutside"+B+" "+N:$+" taskTextOutsideRight taskTextOutside"+B+" "+N+" width-"+Y:$+" taskText taskText"+B+" "+N+" width-"+Y}),ct().securityLevel==="sandbox"){let e;e=pt("#i"+i);let _=e.nodes()[0].contentDocument;w.filter(function(F){return s.has(F.id)}).each(function(F){var Y=_.querySelector("#"+CSS.escape(i+"-"+F.id)),$=_.querySelector("#"+CSS.escape(i+"-"+F.id+"-text"));let B=Y.parentNode;var N=_.createElement("a");N.setAttribute("xlink:href",s.get(F.id)),N.setAttribute("target","_top"),B.appendChild(N),N.appendChild(Y),N.appendChild($)})}}S(X,"drawRects"),c(X,"drawRects");function U(f,y,g,b,v,o,l,h){if(l.length===0&&h.length===0)return;let m,w;for(let{startTime:Y,endTime:$}of o)(m===void 0||Y<m)&&(m=Y),(w===void 0||$>w)&&(w=$);if(!m||!w)return;if((0,kt.default)(w).diff((0,kt.default)(m),"year")>5){st.warn("The difference between the min and max time is more than 5 years. This will cause performance issues. Skipping drawing exclude days.");return}let s=n.db.getDateFormat(),W=[],e=null,_=(0,kt.default)(m);for(;_.valueOf()<=w;)n.db.isInvalidDate(_,s,l,h)?e?e.end=_:e={start:_,end:_}:e&&(W.push(e),e=null),_=_.add(1,"d");x.append("g").selectAll("rect").data(W).enter().append("rect").attr("id",Y=>i+"-exclude-"+Y.start.format("YYYY-MM-DD")).attr("x",Y=>k(Y.start.startOf("day"))+g).attr("y",r.gridLineStartPadding).attr("width",Y=>k(Y.end.endOf("day"))-k(Y.start.startOf("day"))).attr("height",v-y-r.gridLineStartPadding).attr("transform-origin",function(Y,$){return(k(Y.start)+g+.5*(k(Y.end)-k(Y.start))).toString()+"px "+($*f+.5*v).toString()+"px"}).attr("class","exclude-range")}S(U,"drawExcludeDays"),c(U,"drawExcludeDays");function I(f,y,g,b){if(g<=0||f>y)return 1/0;let v=y-f,o=kt.default.duration({[b??"day"]:g}).asMilliseconds();return o<=0?1/0:Math.ceil(v/o)}S(I,"getEstimatedTickCount"),c(I,"getEstimatedTickCount");function T(f,y,g,b){let v=n.db.getDateFormat(),o=n.db.getAxisFormat(),l;o?l=o:v==="D"?l="%d":l=r.axisFormat??"%Y-%m-%d";let h=be(k).tickSize(-b+y+r.gridLineStartPadding).tickFormat(Wt(l)),w=/^([1-9]\d*)(millisecond|second|minute|hour|day|week|month)$/.exec(n.db.getTickInterval()||r.tickInterval);if(w!==null){let s=parseInt(w[1],10);if(isNaN(s)||s<=0)st.warn(`Invalid tick interval value: "${w[1]}". Skipping custom tick interval.`);else{let W=w[2],e=n.db.getWeekday()||r.weekday,_=k.domain(),F=_[0],Y=_[1],$=I(F,Y,s,W);if($>Gt)st.warn(`The tick interval "${s}${W}" would generate ${$} ticks, which exceeds the maximum allowed (${Gt}). This may indicate an invalid date or time range. Skipping custom tick interval.`);else switch(W){case"millisecond":h.ticks(Yt.every(s));break;case"second":h.ticks($t.every(s));break;case"minute":h.ticks(Lt.every(s));break;case"hour":h.ticks(At.every(s));break;case"day":h.ticks(Ft.every(s));break;case"week":h.ticks(Ve[e].every(s));break;case"month":h.ticks(Ot.every(s));break}}}if(x.append("g").attr("class","grid").attr("transform","translate("+f+", "+(b-50)+")").call(h).selectAll("text").style("text-anchor","middle").attr("fill","#000").attr("stroke","none").attr("font-size",10).attr("dy","1em"),n.db.topAxisEnabled()||r.topAxis){let s=Te(k).tickSize(-b+y+r.gridLineStartPadding).tickFormat(Wt(l));if(w!==null){let W=parseInt(w[1],10);if(isNaN(W)||W<=0)st.warn(`Invalid tick interval value: "${w[1]}". Skipping custom tick interval.`);else{let e=w[2],_=n.db.getWeekday()||r.weekday,F=k.domain(),Y=F[0],$=F[1];if(I(Y,$,W,e)<=Gt)switch(e){case"millisecond":s.ticks(Yt.every(W));break;case"second":s.ticks($t.every(W));break;case"minute":s.ticks(Lt.every(W));break;case"hour":s.ticks(At.every(W));break;case"day":s.ticks(Ft.every(W));break;case"week":s.ticks(Ve[_].every(W));break;case"month":s.ticks(Ot.every(W));break}}}x.append("g").attr("class","grid").attr("transform","translate("+f+", "+y+")").call(s).selectAll("text").style("text-anchor","middle").attr("fill","#000").attr("stroke","none").attr("font-size",10)}}S(T,"makeGrid"),c(T,"makeGrid");function d(f,y){let g=0,b=Object.keys(H).map(v=>[v,H[v]]);x.append("g").selectAll("text").data(b).enter().append(function(v){let o=v[0].split(le.lineBreakRegex),l=-(o.length-1)/2,h=C.createElementNS("http://www.w3.org/2000/svg","text");h.setAttribute("dy",l+"em");for(let[m,w]of o.entries()){let s=C.createElementNS("http://www.w3.org/2000/svg","tspan");s.setAttribute("alignment-baseline","central"),s.setAttribute("x","10"),m>0&&s.setAttribute("dy","1em"),s.textContent=w,h.appendChild(s)}return h}).attr("x",10).attr("y",function(v,o){if(o>0)for(let l=0;l<o;l++)return g+=b[o-1][1],v[1]*f/2+g*f+y;else return v[1]*f/2+y}).attr("font-size",r.sectionFontSize).attr("class",function(v){for(let[o,l]of P.entries())if(v[0]===l)return"sectionTitle sectionTitle"+o%r.numberSectionStyles;return"sectionTitle"})}S(d,"vertLabels"),c(d,"vertLabels");function E(f,y,g,b){let v=n.db.getTodayMarker();if(v==="off")return;let o=x.append("g").attr("class","today"),l=new Date,h=o.append("line");h.attr("x1",k(l)+f).attr("x2",k(l)+f).attr("y1",r.titleTopMargin).attr("y2",b-r.titleTopMargin).attr("class","today"),v!==""&&h.attr("style",v.replace(/,/g,";"))}S(E,"drawToday"),c(E,"drawToday");function A(f){let y={},g=[];for(let b=0,v=f.length;b<v;++b)Object.prototype.hasOwnProperty.call(y,f[b])||(y[f[b]]=!0,g.push(f[b]));return g}S(A,"checkUnique"),c(A,"checkUnique")},"draw"),Ri={setConf:Pi,draw:Ni},zi=c(t=>`
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
`,"getStyles"),Hi=zi,Ki={parser:ti,db:Wi,renderer:Ri,styles:Hi};export{Ki as diagram};
//# sourceMappingURL=ganttDiagram-6RSMTGT7-QJHL4AR5.js.map
