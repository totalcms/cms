import{q as $e}from"./chunk-GEYSNACY.js";import{a as ei}from"./chunk-4BVL6VY4.js";import{N as le,O as ue,T as de,U as fe,V as he,W as me,X as ke,Y as ye,Z as ge,_ as ct}from"./chunk-UEGXJBAQ.js";import{A as Ie,B as Ot,C as Wt,D as Ye,a as ce,b as tt,d as pe,e as ve,f as Te,g as be,h as Tt,i as xe,o as we,p as Yt,q as $t,r as Lt,s as At,t as Ft,u as _e,v as De,w as Se,x as Ce,y as Me,z as Ee}from"./chunk-6DTRDB76.js";import{a as l}from"./chunk-Y43I3YIM.js";import"./chunk-GY7RRZSA.js";import{a as D,c as _t,d as ot}from"./chunk-N5O52Q2M.js";var Le=_t((Vt,Pt)=>{(function(t,e){typeof Vt=="object"&&typeof Pt<"u"?Pt.exports=e():typeof define=="function"&&define.amd?define(e):(t=typeof globalThis<"u"?globalThis:t||self).dayjs_plugin_isoWeek=e()})(Vt,(function(){"use strict";var t="day";return function(e,r,s){var n=D(function(_){return _.add(4-_.isoWeekday(),t)},"a"),f=r.prototype;f.isoWeekYear=function(){return n(this).year()},f.isoWeek=function(_){if(!this.$utils().u(_))return this.add(7*(_-this.isoWeek()),t);var S,H,$,P,z=n(this),j=(S=this.isoWeekYear(),H=this.$u,$=(H?s.utc:s)().year(S).startOf("year"),P=4-$.isoWeekday(),$.isoWeekday()>4&&(P+=7),$.add(P,t));return z.diff(j,"week")+1},f.isoWeekday=function(_){return this.$utils().u(_)?this.day()||7:this.day(this.day()%7?_:_-7)};var y=f.startOf;f.startOf=function(_,S){var H=this.$utils(),$=!!H.u(S)||S;return H.p(_)==="isoweek"?$?this.date(this.date()-(this.isoWeekday()-1)).startOf("day"):this.date(this.date()-1-(this.isoWeekday()-1)+7).endOf("day"):y.bind(this)(_,S)}}}))});var Ae=_t((zt,Nt)=>{(function(t,e){typeof zt=="object"&&typeof Nt<"u"?Nt.exports=e():typeof define=="function"&&define.amd?define(e):(t=typeof globalThis<"u"?globalThis:t||self).dayjs_plugin_customParseFormat=e()})(zt,(function(){"use strict";var t={LTS:"h:mm:ss A",LT:"h:mm A",L:"MM/DD/YYYY",LL:"MMMM D, YYYY",LLL:"MMMM D, YYYY h:mm A",LLLL:"dddd, MMMM D, YYYY h:mm A"},e=/(\[[^[]*\])|([-_:/.,()\s]+)|(A|a|Q|YYYY|YY?|ww?|MM?M?M?|Do|DD?|hh?|HH?|mm?|ss?|S{1,3}|z|ZZ?)/g,r=/\d/,s=/\d\d/,n=/\d\d?/,f=/\d*[^-_:/,()\s\d]+/,y={},_=D(function(b){return(b=+b)+(b>68?1900:2e3)},"a"),S=D(function(b){return function(w){this[b]=+w}},"f"),H=[/[+-]\d\d:?(\d\d)?|Z/,function(b){(this.zone||(this.zone={})).offset=(function(w){if(!w||w==="Z")return 0;var x=w.match(/([+-]|\d\d)/g),F=60*x[1]+(+x[2]||0);return F===0?0:x[0]==="+"?-F:F})(b)}],$=D(function(b){var w=y[b];return w&&(w.indexOf?w:w.s.concat(w.f))},"u"),P=D(function(b,w){var x,F=y.meridiem;if(F){for(var X=1;X<=24;X+=1)if(b.indexOf(F(X,0,w))>-1){x=X>12;break}}else x=b===(w?"pm":"PM");return x},"d"),z={A:[f,function(b){this.afternoon=P(b,!1)}],a:[f,function(b){this.afternoon=P(b,!0)}],Q:[r,function(b){this.month=3*(b-1)+1}],S:[r,function(b){this.milliseconds=100*+b}],SS:[s,function(b){this.milliseconds=10*+b}],SSS:[/\d{3}/,function(b){this.milliseconds=+b}],s:[n,S("seconds")],ss:[n,S("seconds")],m:[n,S("minutes")],mm:[n,S("minutes")],H:[n,S("hours")],h:[n,S("hours")],HH:[n,S("hours")],hh:[n,S("hours")],D:[n,S("day")],DD:[s,S("day")],Do:[f,function(b){var w=y.ordinal,x=b.match(/\d+/);if(this.day=x[0],w)for(var F=1;F<=31;F+=1)w(F).replace(/\[|\]/g,"")===b&&(this.day=F)}],w:[n,S("week")],ww:[s,S("week")],M:[n,S("month")],MM:[s,S("month")],MMM:[f,function(b){var w=$("months"),x=($("monthsShort")||w.map((function(F){return F.slice(0,3)}))).indexOf(b)+1;if(x<1)throw new Error;this.month=x%12||x}],MMMM:[f,function(b){var w=$("months").indexOf(b)+1;if(w<1)throw new Error;this.month=w%12||w}],Y:[/[+-]?\d+/,S("year")],YY:[s,function(b){this.year=_(b)}],YYYY:[/\d{4}/,S("year")],Z:H,ZZ:H};function j(b){var w,x;w=b,x=y&&y.formats;for(var F=(b=w.replace(/(\[[^\]]+])|(LTS?|l{1,4}|L{1,4})/g,(function(V,O,k){var p=k&&k.toUpperCase();return O||x[k]||t[k]||x[p].replace(/(\[[^\]]+])|(MMMM|MM|DD|dddd)/g,(function(v,T,a){return T||a.slice(1)}))}))).match(e),X=F.length,q=0;q<X;q+=1){var L=F[q],g=z[L],m=g&&g[0],Y=g&&g[1];F[q]=Y?{regex:m,parser:Y}:L.replace(/^\[|\]$/g,"")}return function(V){for(var O={},k=0,p=0;k<X;k+=1){var v=F[k];if(typeof v=="string")p+=v.length;else{var T=v.regex,a=v.parser,h=V.slice(p),d=T.exec(h)[0];a.call(O,d),V=V.replace(d,"")}}return(function(u){var M=u.afternoon;if(M!==void 0){var i=u.hours;M?i<12&&(u.hours+=12):i===12&&(u.hours=0),delete u.afternoon}})(O),O}}return D(j,"l"),function(b,w,x){x.p.customParseFormat=!0,b&&b.parseTwoDigitYear&&(_=b.parseTwoDigitYear);var F=w.prototype,X=F.parse;F.parse=function(q){var L=q.date,g=q.utc,m=q.args;this.$u=g;var Y=m[1];if(typeof Y=="string"){var V=m[2]===!0,O=m[3]===!0,k=V||O,p=m[2];O&&(p=m[2]),y=this.$locale(),!V&&p&&(y=x.Ls[p]),this.$d=(function(h,d,u,M){try{if(["x","X"].indexOf(d)>-1)return new Date((d==="X"?1e3:1)*h);var i=j(d)(h),E=i.year,c=i.month,B=i.day,o=i.hours,C=i.minutes,I=i.seconds,N=i.milliseconds,R=i.zone,A=i.week,W=new Date,it=B||(E||c?1:W.getDate()),st=E||W.getFullYear(),ut=0;E&&!c||(ut=c>0?c-1:W.getMonth());var dt,ft=o||0,G=C||0,at=I||0,J=N||0;return R?new Date(Date.UTC(st,ut,it,ft,G,at,J+60*R.offset*1e3)):u?new Date(Date.UTC(st,ut,it,ft,G,at,J)):(dt=new Date(st,ut,it,ft,G,at,J),A&&(dt=M(dt).week(A).toDate()),dt)}catch{return new Date("")}})(L,Y,g,x),this.init(),p&&p!==!0&&(this.$L=this.locale(p).$L),k&&L!=this.format(Y)&&(this.$d=new Date("")),y={}}else if(Y instanceof Array)for(var v=Y.length,T=1;T<=v;T+=1){m[1]=Y[T-1];var a=x.apply(this,m);if(a.isValid()){this.$d=a.$d,this.$L=a.$L,this.init();break}T===v&&(this.$d=new Date(""))}else X.call(this,q)}}}))});var Fe=_t((Rt,Ht)=>{(function(t,e){typeof Rt=="object"&&typeof Ht<"u"?Ht.exports=e():typeof define=="function"&&define.amd?define(e):(t=typeof globalThis<"u"?globalThis:t||self).dayjs_plugin_advancedFormat=e()})(Rt,(function(){"use strict";return function(t,e){var r=e.prototype,s=r.format;r.format=function(n){var f=this,y=this.$locale();if(!this.isValid())return s.bind(this)(n);var _=this.$utils(),S=(n||"YYYY-MM-DDTHH:mm:ssZ").replace(/\[([^\]]+)]|Q|wo|ww|w|WW|W|zzz|z|gggg|GGGG|Do|X|x|k{1,2}|S/g,(function(H){switch(H){case"Q":return Math.ceil((f.$M+1)/3);case"Do":return y.ordinal(f.$D);case"gggg":return f.weekYear();case"GGGG":return f.isoWeekYear();case"wo":return y.ordinal(f.week(),"W");case"w":case"ww":return _.s(f.week(),H==="w"?1:2,"0");case"W":case"WW":return _.s(f.isoWeek(),H==="W"?1:2,"0");case"k":case"kk":return _.s(String(f.$H===0?24:f.$H),H==="k"?1:2,"0");case"X":return Math.floor(f.$d.getTime()/1e3);case"x":return f.$d.getTime();case"z":return"["+f.offsetName()+"]";case"zzz":return"["+f.offsetName("long")+"]";default:return H}}));return s.bind(this)(S)}}}))});var Oe=_t((jt,Bt)=>{(function(t,e){typeof jt=="object"&&typeof Bt<"u"?Bt.exports=e():typeof define=="function"&&define.amd?define(e):(t=typeof globalThis<"u"?globalThis:t||self).dayjs_plugin_duration=e()})(jt,(function(){"use strict";var t,e,r=1e3,s=6e4,n=36e5,f=864e5,y=31536e6,_=2628e6,S=/^(-|\+)?P(?:([-+]?[0-9,.]*)Y)?(?:([-+]?[0-9,.]*)M)?(?:([-+]?[0-9,.]*)W)?(?:([-+]?[0-9,.]*)D)?(?:T(?:([-+]?[0-9,.]*)H)?(?:([-+]?[0-9,.]*)M)?(?:([-+]?[0-9,.]*)S)?)?$/,H=/\[([^\]]+)]|YYYY|YY|Y|M{1,2}|D{1,2}|H{1,2}|m{1,2}|s{1,2}|SSS/g,$={years:y,months:_,days:f,hours:n,minutes:s,seconds:r,milliseconds:1,weeks:6048e5},P=D(function(L){return L instanceof X},"c"),z=D(function(L,g,m){return new X(L,m,g.$l)},"f"),j=D(function(L){return e.p(L)+"s"},"m"),b=D(function(L){return L<0},"l"),w=D(function(L){return b(L)?Math.ceil(L):Math.floor(L)},"$"),x=D(function(L){return Math.abs(L)},"y"),F=D(function(L,g){return L?b(L)?{negative:!0,format:""+x(L)+g}:{negative:!1,format:""+L+g}:{negative:!1,format:""}},"v"),X=(function(){function L(m,Y,V){var O=this;if(this.$d={},this.$l=V,m===void 0&&(this.$ms=0,this.parseFromMilliseconds()),Y)return z(m*$[j(Y)],this);if(typeof m=="number")return this.$ms=m,this.parseFromMilliseconds(),this;if(typeof m=="object")return Object.keys(m).forEach((function(v){O.$d[j(v)]=m[v]})),this.calMilliseconds(),this;if(typeof m=="string"){var k=m.match(S);if(k){var p=k.slice(2).map((function(v){return v!=null?Number(v):0}));return this.$d.years=p[0],this.$d.months=p[1],this.$d.weeks=p[2],this.$d.days=p[3],this.$d.hours=p[4],this.$d.minutes=p[5],this.$d.seconds=p[6],this.calMilliseconds(),this}}return this}D(L,"l");var g=L.prototype;return g.calMilliseconds=function(){var m=this;this.$ms=Object.keys(this.$d).reduce((function(Y,V){return Y+(m.$d[V]||0)*$[V]}),0)},g.parseFromMilliseconds=function(){var m=this.$ms;this.$d.years=w(m/y),m%=y,this.$d.months=w(m/_),m%=_,this.$d.days=w(m/f),m%=f,this.$d.hours=w(m/n),m%=n,this.$d.minutes=w(m/s),m%=s,this.$d.seconds=w(m/r),m%=r,this.$d.milliseconds=m},g.toISOString=function(){var m=F(this.$d.years,"Y"),Y=F(this.$d.months,"M"),V=+this.$d.days||0;this.$d.weeks&&(V+=7*this.$d.weeks);var O=F(V,"D"),k=F(this.$d.hours,"H"),p=F(this.$d.minutes,"M"),v=this.$d.seconds||0;this.$d.milliseconds&&(v+=this.$d.milliseconds/1e3,v=Math.round(1e3*v)/1e3);var T=F(v,"S"),a=m.negative||Y.negative||O.negative||k.negative||p.negative||T.negative,h=k.format||p.format||T.format?"T":"",d=(a?"-":"")+"P"+m.format+Y.format+O.format+h+k.format+p.format+T.format;return d==="P"||d==="-P"?"P0D":d},g.toJSON=function(){return this.toISOString()},g.format=function(m){var Y=m||"YYYY-MM-DDTHH:mm:ss",V={Y:this.$d.years,YY:e.s(this.$d.years,2,"0"),YYYY:e.s(this.$d.years,4,"0"),M:this.$d.months,MM:e.s(this.$d.months,2,"0"),D:this.$d.days,DD:e.s(this.$d.days,2,"0"),H:this.$d.hours,HH:e.s(this.$d.hours,2,"0"),m:this.$d.minutes,mm:e.s(this.$d.minutes,2,"0"),s:this.$d.seconds,ss:e.s(this.$d.seconds,2,"0"),SSS:e.s(this.$d.milliseconds,3,"0")};return Y.replace(H,(function(O,k){return k||String(V[O])}))},g.as=function(m){return this.$ms/$[j(m)]},g.get=function(m){var Y=this.$ms,V=j(m);return V==="milliseconds"?Y%=1e3:Y=V==="weeks"?w(Y/$[V]):this.$d[V],Y||0},g.add=function(m,Y,V){var O;return O=Y?m*$[j(Y)]:P(m)?m.$ms:z(m,this).$ms,z(this.$ms+O*(V?-1:1),this)},g.subtract=function(m,Y){return this.add(m,Y,!0)},g.locale=function(m){var Y=this.clone();return Y.$l=m,Y},g.clone=function(){return z(this.$ms,this)},g.humanize=function(m){return t().add(this.$ms,"ms").locale(this.$l).fromNow(!m)},g.valueOf=function(){return this.asMilliseconds()},g.milliseconds=function(){return this.get("milliseconds")},g.asMilliseconds=function(){return this.as("milliseconds")},g.seconds=function(){return this.get("seconds")},g.asSeconds=function(){return this.as("seconds")},g.minutes=function(){return this.get("minutes")},g.asMinutes=function(){return this.as("minutes")},g.hours=function(){return this.get("hours")},g.asHours=function(){return this.as("hours")},g.days=function(){return this.get("days")},g.asDays=function(){return this.as("days")},g.weeks=function(){return this.get("weeks")},g.asWeeks=function(){return this.as("weeks")},g.months=function(){return this.get("months")},g.asMonths=function(){return this.as("months")},g.years=function(){return this.get("years")},g.asYears=function(){return this.as("years")},L})(),q=D(function(L,g,m){return L.add(g.years()*m,"y").add(g.months()*m,"M").add(g.days()*m,"d").add(g.hours()*m,"h").add(g.minutes()*m,"m").add(g.seconds()*m,"s").add(g.milliseconds()*m,"ms")},"p");return function(L,g,m){t=m,e=m().$utils(),m.duration=function(O,k){var p=m.locale();return z(O,{$l:p},k)},m.isDuration=P;var Y=g.prototype.add,V=g.prototype.subtract;g.prototype.add=function(O,k){return P(O)?q(this,O,1):Y.bind(this)(O,k)},g.prototype.subtract=function(O,k){return P(O)?q(this,O,-1):V.bind(this)(O,k)}}}))});var ze=ot(ei(),1),K=ot(ce(),1),Ne=ot(Le(),1),Re=ot(Ae(),1),He=ot(Fe(),1),kt=ot(ce(),1),ti=ot(Oe(),1);var Ut=(function(){var t=l(function(T,a,h,d){for(h=h||{},d=T.length;d--;h[T[d]]=a);return h},"o"),e=[6,8,10,12,13,14,15,16,17,18,20,21,22,23,24,25,26,27,28,29,30,31,33,35,36,38,40],r=[1,26],s=[1,27],n=[1,28],f=[1,29],y=[1,30],_=[1,31],S=[1,32],H=[1,33],$=[1,34],P=[1,9],z=[1,10],j=[1,11],b=[1,12],w=[1,13],x=[1,14],F=[1,15],X=[1,16],q=[1,19],L=[1,20],g=[1,21],m=[1,22],Y=[1,23],V=[1,25],O=[1,35],k={trace:l(D(function(){},"trace"),"trace"),yy:{},symbols_:{error:2,start:3,gantt:4,document:5,EOF:6,line:7,SPACE:8,statement:9,NL:10,weekday:11,weekday_monday:12,weekday_tuesday:13,weekday_wednesday:14,weekday_thursday:15,weekday_friday:16,weekday_saturday:17,weekday_sunday:18,weekend:19,weekend_friday:20,weekend_saturday:21,dateFormat:22,inclusiveEndDates:23,topAxis:24,axisFormat:25,tickInterval:26,excludes:27,includes:28,todayMarker:29,title:30,acc_title:31,acc_title_value:32,acc_descr:33,acc_descr_value:34,acc_descr_multiline_value:35,section:36,clickStatement:37,taskTxt:38,taskData:39,click:40,callbackname:41,callbackargs:42,href:43,clickStatementDebug:44,$accept:0,$end:1},terminals_:{2:"error",4:"gantt",6:"EOF",8:"SPACE",10:"NL",12:"weekday_monday",13:"weekday_tuesday",14:"weekday_wednesday",15:"weekday_thursday",16:"weekday_friday",17:"weekday_saturday",18:"weekday_sunday",20:"weekend_friday",21:"weekend_saturday",22:"dateFormat",23:"inclusiveEndDates",24:"topAxis",25:"axisFormat",26:"tickInterval",27:"excludes",28:"includes",29:"todayMarker",30:"title",31:"acc_title",32:"acc_title_value",33:"acc_descr",34:"acc_descr_value",35:"acc_descr_multiline_value",36:"section",38:"taskTxt",39:"taskData",40:"click",41:"callbackname",42:"callbackargs",43:"href"},productions_:[0,[3,3],[5,0],[5,2],[7,2],[7,1],[7,1],[7,1],[11,1],[11,1],[11,1],[11,1],[11,1],[11,1],[11,1],[19,1],[19,1],[9,1],[9,1],[9,1],[9,1],[9,1],[9,1],[9,1],[9,1],[9,1],[9,1],[9,1],[9,2],[9,2],[9,1],[9,1],[9,1],[9,2],[37,2],[37,3],[37,3],[37,4],[37,3],[37,4],[37,2],[44,2],[44,3],[44,3],[44,4],[44,3],[44,4],[44,2]],performAction:l(D(function(a,h,d,u,M,i,E){var c=i.length-1;switch(M){case 1:return i[c-1];case 2:this.$=[];break;case 3:i[c-1].push(i[c]),this.$=i[c-1];break;case 4:case 5:this.$=i[c];break;case 6:case 7:this.$=[];break;case 8:u.setWeekday("monday");break;case 9:u.setWeekday("tuesday");break;case 10:u.setWeekday("wednesday");break;case 11:u.setWeekday("thursday");break;case 12:u.setWeekday("friday");break;case 13:u.setWeekday("saturday");break;case 14:u.setWeekday("sunday");break;case 15:u.setWeekend("friday");break;case 16:u.setWeekend("saturday");break;case 17:u.setDateFormat(i[c].substr(11)),this.$=i[c].substr(11);break;case 18:u.enableInclusiveEndDates(),this.$=i[c].substr(18);break;case 19:u.TopAxis(),this.$=i[c].substr(8);break;case 20:u.setAxisFormat(i[c].substr(11)),this.$=i[c].substr(11);break;case 21:u.setTickInterval(i[c].substr(13)),this.$=i[c].substr(13);break;case 22:u.setExcludes(i[c].substr(9)),this.$=i[c].substr(9);break;case 23:u.setIncludes(i[c].substr(9)),this.$=i[c].substr(9);break;case 24:u.setTodayMarker(i[c].substr(12)),this.$=i[c].substr(12);break;case 27:u.setDiagramTitle(i[c].substr(6)),this.$=i[c].substr(6);break;case 28:this.$=i[c].trim(),u.setAccTitle(this.$);break;case 29:case 30:this.$=i[c].trim(),u.setAccDescription(this.$);break;case 31:u.addSection(i[c].substr(8)),this.$=i[c].substr(8);break;case 33:u.addTask(i[c-1],i[c]),this.$="task";break;case 34:this.$=i[c-1],u.setClickEvent(i[c-1],i[c],null);break;case 35:this.$=i[c-2],u.setClickEvent(i[c-2],i[c-1],i[c]);break;case 36:this.$=i[c-2],u.setClickEvent(i[c-2],i[c-1],null),u.setLink(i[c-2],i[c]);break;case 37:this.$=i[c-3],u.setClickEvent(i[c-3],i[c-2],i[c-1]),u.setLink(i[c-3],i[c]);break;case 38:this.$=i[c-2],u.setClickEvent(i[c-2],i[c],null),u.setLink(i[c-2],i[c-1]);break;case 39:this.$=i[c-3],u.setClickEvent(i[c-3],i[c-1],i[c]),u.setLink(i[c-3],i[c-2]);break;case 40:this.$=i[c-1],u.setLink(i[c-1],i[c]);break;case 41:case 47:this.$=i[c-1]+" "+i[c];break;case 42:case 43:case 45:this.$=i[c-2]+" "+i[c-1]+" "+i[c];break;case 44:case 46:this.$=i[c-3]+" "+i[c-2]+" "+i[c-1]+" "+i[c];break}},"anonymous"),"anonymous"),table:[{3:1,4:[1,2]},{1:[3]},t(e,[2,2],{5:3}),{6:[1,4],7:5,8:[1,6],9:7,10:[1,8],11:17,12:r,13:s,14:n,15:f,16:y,17:_,18:S,19:18,20:H,21:$,22:P,23:z,24:j,25:b,26:w,27:x,28:F,29:X,30:q,31:L,33:g,35:m,36:Y,37:24,38:V,40:O},t(e,[2,7],{1:[2,1]}),t(e,[2,3]),{9:36,11:17,12:r,13:s,14:n,15:f,16:y,17:_,18:S,19:18,20:H,21:$,22:P,23:z,24:j,25:b,26:w,27:x,28:F,29:X,30:q,31:L,33:g,35:m,36:Y,37:24,38:V,40:O},t(e,[2,5]),t(e,[2,6]),t(e,[2,17]),t(e,[2,18]),t(e,[2,19]),t(e,[2,20]),t(e,[2,21]),t(e,[2,22]),t(e,[2,23]),t(e,[2,24]),t(e,[2,25]),t(e,[2,26]),t(e,[2,27]),{32:[1,37]},{34:[1,38]},t(e,[2,30]),t(e,[2,31]),t(e,[2,32]),{39:[1,39]},t(e,[2,8]),t(e,[2,9]),t(e,[2,10]),t(e,[2,11]),t(e,[2,12]),t(e,[2,13]),t(e,[2,14]),t(e,[2,15]),t(e,[2,16]),{41:[1,40],43:[1,41]},t(e,[2,4]),t(e,[2,28]),t(e,[2,29]),t(e,[2,33]),t(e,[2,34],{42:[1,42],43:[1,43]}),t(e,[2,40],{41:[1,44]}),t(e,[2,35],{43:[1,45]}),t(e,[2,36]),t(e,[2,38],{42:[1,46]}),t(e,[2,37]),t(e,[2,39])],defaultActions:{},parseError:l(D(function(a,h){if(h.recoverable)this.trace(a);else{var d=new Error(a);throw d.hash=h,d}},"parseError"),"parseError"),parse:l(D(function(a){var h=this,d=[0],u=[],M=[null],i=[],E=this.table,c="",B=0,o=0,C=0,I=2,N=1,R=i.slice.call(arguments,1),A=Object.create(this.lexer),W={yy:{}};for(var it in this.yy)Object.prototype.hasOwnProperty.call(this.yy,it)&&(W.yy[it]=this.yy[it]);A.setInput(a,W.yy),W.yy.lexer=A,W.yy.parser=this,typeof A.yylloc>"u"&&(A.yylloc={});var st=A.yylloc;i.push(st);var ut=A.options&&A.options.ranges;typeof W.yy.parseError=="function"?this.parseError=W.yy.parseError:this.parseError=Object.getPrototypeOf(this).parseError;function dt(Q){d.length=d.length-2*Q,M.length=M.length-Q,i.length=i.length-Q}D(dt,"popStack"),l(dt,"popStack");function ft(){var Q;return Q=u.pop()||A.lex()||N,typeof Q!="number"&&(Q instanceof Array&&(u=Q,Q=u.pop()),Q=h.symbols_[Q]||Q),Q}D(ft,"lex"),l(ft,"lex");for(var G,at,J,Z,Gi,Et,ht={},xt,nt,oe,wt;;){if(J=d[d.length-1],this.defaultActions[J]?Z=this.defaultActions[J]:((G===null||typeof G>"u")&&(G=ft()),Z=E[J]&&E[J][G]),typeof Z>"u"||!Z.length||!Z[0]){var It="";wt=[];for(xt in E[J])this.terminals_[xt]&&xt>I&&wt.push("'"+this.terminals_[xt]+"'");A.showPosition?It="Parse error on line "+(B+1)+`:
`+A.showPosition()+`
Expecting `+wt.join(", ")+", got '"+(this.terminals_[G]||G)+"'":It="Parse error on line "+(B+1)+": Unexpected "+(G==N?"end of input":"'"+(this.terminals_[G]||G)+"'"),this.parseError(It,{text:A.match,token:this.terminals_[G]||G,line:A.yylineno,loc:st,expected:wt})}if(Z[0]instanceof Array&&Z.length>1)throw new Error("Parse Error: multiple actions possible at state: "+J+", token: "+G);switch(Z[0]){case 1:d.push(G),M.push(A.yytext),i.push(A.yylloc),d.push(Z[1]),G=null,at?(G=at,at=null):(o=A.yyleng,c=A.yytext,B=A.yylineno,st=A.yylloc,C>0&&C--);break;case 2:if(nt=this.productions_[Z[1]][1],ht.$=M[M.length-nt],ht._$={first_line:i[i.length-(nt||1)].first_line,last_line:i[i.length-1].last_line,first_column:i[i.length-(nt||1)].first_column,last_column:i[i.length-1].last_column},ut&&(ht._$.range=[i[i.length-(nt||1)].range[0],i[i.length-1].range[1]]),Et=this.performAction.apply(ht,[c,o,B,W.yy,Z[1],M,i].concat(R)),typeof Et<"u")return Et;nt&&(d=d.slice(0,-1*nt*2),M=M.slice(0,-1*nt),i=i.slice(0,-1*nt)),d.push(this.productions_[Z[1]][0]),M.push(ht.$),i.push(ht._$),oe=E[d[d.length-2]][d[d.length-1]],d.push(oe);break;case 3:return!0}}return!0},"parse"),"parse")},p=(function(){var T={EOF:1,parseError:l(D(function(h,d){if(this.yy.parser)this.yy.parser.parseError(h,d);else throw new Error(h)},"parseError"),"parseError"),setInput:l(function(a,h){return this.yy=h||this.yy||{},this._input=a,this._more=this._backtrack=this.done=!1,this.yylineno=this.yyleng=0,this.yytext=this.matched=this.match="",this.conditionStack=["INITIAL"],this.yylloc={first_line:1,first_column:0,last_line:1,last_column:0},this.options.ranges&&(this.yylloc.range=[0,0]),this.offset=0,this},"setInput"),input:l(function(){var a=this._input[0];this.yytext+=a,this.yyleng++,this.offset++,this.match+=a,this.matched+=a;var h=a.match(/(?:\r\n?|\n).*/g);return h?(this.yylineno++,this.yylloc.last_line++):this.yylloc.last_column++,this.options.ranges&&this.yylloc.range[1]++,this._input=this._input.slice(1),a},"input"),unput:l(function(a){var h=a.length,d=a.split(/(?:\r\n?|\n)/g);this._input=a+this._input,this.yytext=this.yytext.substr(0,this.yytext.length-h),this.offset-=h;var u=this.match.split(/(?:\r\n?|\n)/g);this.match=this.match.substr(0,this.match.length-1),this.matched=this.matched.substr(0,this.matched.length-1),d.length-1&&(this.yylineno-=d.length-1);var M=this.yylloc.range;return this.yylloc={first_line:this.yylloc.first_line,last_line:this.yylineno+1,first_column:this.yylloc.first_column,last_column:d?(d.length===u.length?this.yylloc.first_column:0)+u[u.length-d.length].length-d[0].length:this.yylloc.first_column-h},this.options.ranges&&(this.yylloc.range=[M[0],M[0]+this.yyleng-h]),this.yyleng=this.yytext.length,this},"unput"),more:l(function(){return this._more=!0,this},"more"),reject:l(function(){if(this.options.backtrack_lexer)this._backtrack=!0;else return this.parseError("Lexical error on line "+(this.yylineno+1)+`. You can only invoke reject() in the lexer when the lexer is of the backtracking persuasion (options.backtrack_lexer = true).
`+this.showPosition(),{text:"",token:null,line:this.yylineno});return this},"reject"),less:l(function(a){this.unput(this.match.slice(a))},"less"),pastInput:l(function(){var a=this.matched.substr(0,this.matched.length-this.match.length);return(a.length>20?"...":"")+a.substr(-20).replace(/\n/g,"")},"pastInput"),upcomingInput:l(function(){var a=this.match;return a.length<20&&(a+=this._input.substr(0,20-a.length)),(a.substr(0,20)+(a.length>20?"...":"")).replace(/\n/g,"")},"upcomingInput"),showPosition:l(function(){var a=this.pastInput(),h=new Array(a.length+1).join("-");return a+this.upcomingInput()+`
`+h+"^"},"showPosition"),test_match:l(function(a,h){var d,u,M;if(this.options.backtrack_lexer&&(M={yylineno:this.yylineno,yylloc:{first_line:this.yylloc.first_line,last_line:this.last_line,first_column:this.yylloc.first_column,last_column:this.yylloc.last_column},yytext:this.yytext,match:this.match,matches:this.matches,matched:this.matched,yyleng:this.yyleng,offset:this.offset,_more:this._more,_input:this._input,yy:this.yy,conditionStack:this.conditionStack.slice(0),done:this.done},this.options.ranges&&(M.yylloc.range=this.yylloc.range.slice(0))),u=a[0].match(/(?:\r\n?|\n).*/g),u&&(this.yylineno+=u.length),this.yylloc={first_line:this.yylloc.last_line,last_line:this.yylineno+1,first_column:this.yylloc.last_column,last_column:u?u[u.length-1].length-u[u.length-1].match(/\r?\n?/)[0].length:this.yylloc.last_column+a[0].length},this.yytext+=a[0],this.match+=a[0],this.matches=a,this.yyleng=this.yytext.length,this.options.ranges&&(this.yylloc.range=[this.offset,this.offset+=this.yyleng]),this._more=!1,this._backtrack=!1,this._input=this._input.slice(a[0].length),this.matched+=a[0],d=this.performAction.call(this,this.yy,this,h,this.conditionStack[this.conditionStack.length-1]),this.done&&this._input&&(this.done=!1),d)return d;if(this._backtrack){for(var i in M)this[i]=M[i];return!1}return!1},"test_match"),next:l(function(){if(this.done)return this.EOF;this._input||(this.done=!0);var a,h,d,u;this._more||(this.yytext="",this.match="");for(var M=this._currentRules(),i=0;i<M.length;i++)if(d=this._input.match(this.rules[M[i]]),d&&(!h||d[0].length>h[0].length)){if(h=d,u=i,this.options.backtrack_lexer){if(a=this.test_match(d,M[i]),a!==!1)return a;if(this._backtrack){h=!1;continue}else return!1}else if(!this.options.flex)break}return h?(a=this.test_match(h,M[u]),a!==!1?a:!1):this._input===""?this.EOF:this.parseError("Lexical error on line "+(this.yylineno+1)+`. Unrecognized text.
`+this.showPosition(),{text:"",token:null,line:this.yylineno})},"next"),lex:l(D(function(){var h=this.next();return h||this.lex()},"lex"),"lex"),begin:l(D(function(h){this.conditionStack.push(h)},"begin"),"begin"),popState:l(D(function(){var h=this.conditionStack.length-1;return h>0?this.conditionStack.pop():this.conditionStack[0]},"popState"),"popState"),_currentRules:l(D(function(){return this.conditionStack.length&&this.conditionStack[this.conditionStack.length-1]?this.conditions[this.conditionStack[this.conditionStack.length-1]].rules:this.conditions.INITIAL.rules},"_currentRules"),"_currentRules"),topState:l(D(function(h){return h=this.conditionStack.length-1-Math.abs(h||0),h>=0?this.conditionStack[h]:"INITIAL"},"topState"),"topState"),pushState:l(D(function(h){this.begin(h)},"pushState"),"pushState"),stateStackSize:l(D(function(){return this.conditionStack.length},"stateStackSize"),"stateStackSize"),options:{"case-insensitive":!0},performAction:l(D(function(h,d,u,M){var i=M;switch(u){case 0:return this.begin("open_directive"),"open_directive";break;case 1:return this.begin("acc_title"),31;break;case 2:return this.popState(),"acc_title_value";break;case 3:return this.begin("acc_descr"),33;break;case 4:return this.popState(),"acc_descr_value";break;case 5:this.begin("acc_descr_multiline");break;case 6:this.popState();break;case 7:return"acc_descr_multiline_value";case 8:break;case 9:break;case 10:break;case 11:return 10;case 12:break;case 13:break;case 14:this.begin("href");break;case 15:this.popState();break;case 16:return 43;case 17:this.begin("callbackname");break;case 18:this.popState();break;case 19:this.popState(),this.begin("callbackargs");break;case 20:return 41;case 21:this.popState();break;case 22:return 42;case 23:this.begin("click");break;case 24:this.popState();break;case 25:return 40;case 26:return 4;case 27:return 22;case 28:return 23;case 29:return 24;case 30:return 25;case 31:return 26;case 32:return 28;case 33:return 27;case 34:return 29;case 35:return 12;case 36:return 13;case 37:return 14;case 38:return 15;case 39:return 16;case 40:return 17;case 41:return 18;case 42:return 20;case 43:return 21;case 44:return"date";case 45:return 30;case 46:return"accDescription";case 47:return 36;case 48:return 38;case 49:return 39;case 50:return":";case 51:return 6;case 52:return"INVALID"}},"anonymous"),"anonymous"),rules:[/^(?:%%\{)/i,/^(?:accTitle\s*:\s*)/i,/^(?:(?!\n||)*[^\n]*)/i,/^(?:accDescr\s*:\s*)/i,/^(?:(?!\n||)*[^\n]*)/i,/^(?:accDescr\s*\{\s*)/i,/^(?:[\}])/i,/^(?:[^\}]*)/i,/^(?:%%(?!\{)*[^\n]*)/i,/^(?:[^\}]%%*[^\n]*)/i,/^(?:%%*[^\n]*[\n]*)/i,/^(?:[\n]+)/i,/^(?:\s+)/i,/^(?:%[^\n]*)/i,/^(?:href[\s]+["])/i,/^(?:["])/i,/^(?:[^"]*)/i,/^(?:call[\s]+)/i,/^(?:\([\s]*\))/i,/^(?:\()/i,/^(?:[^(]*)/i,/^(?:\))/i,/^(?:[^)]*)/i,/^(?:click[\s]+)/i,/^(?:[\s\n])/i,/^(?:[^\s\n]*)/i,/^(?:gantt\b)/i,/^(?:dateFormat\s[^#\n;]+)/i,/^(?:inclusiveEndDates\b)/i,/^(?:topAxis\b)/i,/^(?:axisFormat\s[^#\n;]+)/i,/^(?:tickInterval\s[^#\n;]+)/i,/^(?:includes\s[^#\n;]+)/i,/^(?:excludes\s[^#\n;]+)/i,/^(?:todayMarker\s[^\n;]+)/i,/^(?:weekday\s+monday\b)/i,/^(?:weekday\s+tuesday\b)/i,/^(?:weekday\s+wednesday\b)/i,/^(?:weekday\s+thursday\b)/i,/^(?:weekday\s+friday\b)/i,/^(?:weekday\s+saturday\b)/i,/^(?:weekday\s+sunday\b)/i,/^(?:weekend\s+friday\b)/i,/^(?:weekend\s+saturday\b)/i,/^(?:\d\d\d\d-\d\d-\d\d\b)/i,/^(?:title\s[^\n]+)/i,/^(?:accDescription\s[^#\n;]+)/i,/^(?:section\s[^\n]+)/i,/^(?:[^:\n]+)/i,/^(?::[^#\n;]+)/i,/^(?::)/i,/^(?:$)/i,/^(?:.)/i],conditions:{acc_descr_multiline:{rules:[6,7],inclusive:!1},acc_descr:{rules:[4],inclusive:!1},acc_title:{rules:[2],inclusive:!1},callbackargs:{rules:[21,22],inclusive:!1},callbackname:{rules:[18,19,20],inclusive:!1},href:{rules:[15,16],inclusive:!1},click:{rules:[24,25],inclusive:!1},INITIAL:{rules:[0,1,3,5,8,9,10,11,12,13,14,17,23,26,27,28,29,30,31,32,33,34,35,36,37,38,39,40,41,42,43,44,45,46,47,48,49,50,51,52],inclusive:!0}}};return T})();k.lexer=p;function v(){this.yy={}}return D(v,"Parser"),l(v,"Parser"),v.prototype=k,k.Parser=v,new v})();Ut.parser=Ut;var ii=Ut;K.default.extend(Ne.default);K.default.extend(Re.default);K.default.extend(He.default);var We={friday:5,saturday:6},et="",Qt="",Kt=void 0,Jt="",gt=[],pt=[],te=new Map,ee=[],Ct=[],vt="",ie="",je=["active","done","crit","milestone","vert"],se=[],mt="",bt=!1,ne=!1,re="sunday",Mt="saturday",Xt=0,si=l(function(){ee=[],Ct=[],vt="",se=[],Dt=0,Zt=void 0,St=void 0,U=[],et="",Qt="",ie="",Kt=void 0,Jt="",gt=[],pt=[],bt=!1,ne=!1,Xt=0,te=new Map,mt="",de(),re="sunday",Mt="saturday"},"clear"),ni=l(function(t){mt=t},"setDiagramId"),ri=l(function(t){Qt=t},"setAxisFormat"),ai=l(function(){return Qt},"getAxisFormat"),oi=l(function(t){Kt=t},"setTickInterval"),ci=l(function(){return Kt},"getTickInterval"),li=l(function(t){Jt=t},"setTodayMarker"),ui=l(function(){return Jt},"getTodayMarker"),di=l(function(t){et=t},"setDateFormat"),fi=l(function(){bt=!0},"enableInclusiveEndDates"),hi=l(function(){return bt},"endDatesAreInclusive"),mi=l(function(){ne=!0},"enableTopAxis"),ki=l(function(){return ne},"topAxisEnabled"),yi=l(function(t){ie=t},"setDisplayMode"),gi=l(function(){return ie},"getDisplayMode"),pi=l(function(){return et},"getDateFormat"),Be=l((t,e)=>{let r=e.toLowerCase().split(/[\s,]+/).filter(s=>s!=="");return[...new Set([...t,...r])]},"mergeTokens"),vi=l(function(t){gt=Be(gt,t)},"setIncludes"),Ti=l(function(){return gt},"getIncludes"),bi=l(function(t){pt=Be(pt,t)},"setExcludes"),xi=l(function(){return pt},"getExcludes"),wi=l(function(){return te},"getLinks"),_i=l(function(t){vt=t,ee.push(t)},"addSection"),Di=l(function(){return ee},"getSections"),Si=l(function(){let t=Ve(),e=10,r=0;for(;!t&&r<e;)t=Ve(),r++;return Ct=U,Ct},"getTasks"),Ge=l(function(t,e,r,s){let n=t.format(e.trim()),f=t.format("YYYY-MM-DD");return s.includes(n)||s.includes(f)?!1:r.includes("weekends")&&(t.isoWeekday()===We[Mt]||t.isoWeekday()===We[Mt]+1)||r.includes(t.format("dddd").toLowerCase())?!0:r.includes(n)||r.includes(f)},"isInvalidDate"),Ci=l(function(t){re=t},"setWeekday"),Mi=l(function(){return re},"getWeekday"),Ei=l(function(t){Mt=t},"setWeekend"),Ue=l(function(t,e,r,s){if(!r.length||t.manualEndTime)return;let n;t.startTime instanceof Date?n=(0,K.default)(t.startTime):n=(0,K.default)(t.startTime,e,!0),n=n.add(1,"d");let f;t.endTime instanceof Date?f=(0,K.default)(t.endTime):f=(0,K.default)(t.endTime,e,!0);let[y,_]=Ii(n,f,e,r,s);t.endTime=y.toDate(),t.renderEndTime=_},"checkTaskDates"),Ii=l(function(t,e,r,s,n){let f=!1,y=null,_=e.add(1e4,"d");for(;t<=e;){if(f||(y=e.toDate()),f=Ge(t,r,s,n),f&&(e=e.add(1,"d"),e>_))throw new Error("Failed to find a valid date that was not excluded by `excludes` after 10,000 iterations.");t=t.add(1,"d")}return[e,y]},"fixTaskDates"),Xe=l(function(t,e){tt.warn(`Gantt: the "${t}" statement references unknown task id(s): ${e.join(", ")}. Make sure the referenced tasks exist and declare an id. Milestones need both an id and a duration, e.g. "Milestone :milestone, m1, 2023-01-01, 0d".`)},"warnAboutUnknownTaskIds"),qt=l(function(t,e,r){if(r=r.trim(),l(_=>{let S=_.trim();return S==="x"||S==="X"},"isTimestampFormat")(e)&&/^\d+$/.test(r))return new Date(Number(r));let f=/^after\s+(?<ids>[\d\w- ]+)/.exec(r);if(f!==null){let _=null,S=[],H=f.groups.ids.split(" ").filter(P=>P!=="");for(let P of H){let z=lt(P);if(z===void 0){S.push(P);continue}(!_||z.endTime>_.endTime)&&(_=z)}if(S.length>0&&Xe("after",S),_)return _.endTime;let $=new Date;return $.setHours(0,0,0,0),$}let y=(0,K.default)(r,e.trim(),!0);if(y.isValid())return y.toDate();{tt.debug("Invalid date:"+r),tt.debug("With date format:"+e.trim());let _=new Date(r);if(_===void 0||isNaN(_.getTime())||_.getFullYear()<-1e4||_.getFullYear()>1e4)throw new Error("Invalid date:"+r);return _}},"getStartDate"),qe=l(function(t){let e=/^(\d+(?:\.\d+)?)([Mdhmswy]|ms)$/.exec(t.trim());return e!==null?[Number.parseFloat(e[1]),e[2]]:[NaN,"ms"]},"parseDuration"),Ze=l(function(t,e,r,s=!1){r=r.trim();let f=/^until\s+(?<ids>[\d\w- ]+)/.exec(r);if(f!==null){let $=null,P=[],z=f.groups.ids.split(" ").filter(b=>b!=="");for(let b of z){let w=lt(b);if(w===void 0){P.push(b);continue}(!$||w.startTime<$.startTime)&&($=w)}if(P.length>0&&Xe("until",P),$)return $.startTime;let j=new Date;return j.setHours(0,0,0,0),j}let y=(0,K.default)(r,e.trim(),!0);if(y.isValid())return s&&(y=y.add(1,"d")),y.toDate();let _=(0,K.default)(t),[S,H]=qe(r);if(Number.isNaN(S))tt.warn(`Gantt: "${r}" is neither a valid date for the "${e.trim()}" date format nor a valid duration (e.g. "3d"), so it is ignored and the task gets a zero duration. Milestones need a duration too, e.g. "Milestone :milestone, m1, 2023-01-01, 0d".`);else{let $=_.add(S,H);$.isValid()&&(_=$)}return _.toDate()},"getEndDate"),Dt=0,yt=l(function(t){return t===void 0?(Dt=Dt+1,"task"+Dt):t},"parseId"),Yi=l(function(t,e){let r;e.substr(0,1)===":"?r=e.substr(1,e.length):r=e;let s=r.split(","),n={};ae(s,n,je);for(let y=0;y<s.length;y++)s[y]=s[y].trim();let f="";switch(s.length){case 1:n.id=yt(),n.startTime=t.endTime,f=s[0];break;case 2:n.id=yt(),n.startTime=qt(void 0,et,s[0]),f=s[1];break;case 3:n.id=yt(s[0]),n.startTime=qt(void 0,et,s[1]),f=s[2];break;default:}return f&&(n.endTime=Ze(n.startTime,et,f,bt),n.manualEndTime=(0,K.default)(f,"YYYY-MM-DD",!0).isValid(),Ue(n,et,pt,gt)),n},"compileData"),$i=l(function(t,e){let r;e.substr(0,1)===":"?r=e.substr(1,e.length):r=e;let s=r.split(","),n={};ae(s,n,je);for(let f=0;f<s.length;f++)s[f]=s[f].trim();switch(s.length){case 1:n.id=yt(),n.startTime={type:"prevTaskEnd",id:t},n.endTime={data:s[0]};break;case 2:n.id=yt(),n.startTime={type:"getStartDate",startData:s[0]},n.endTime={data:s[1]};break;case 3:n.id=yt(s[0]),n.startTime={type:"getStartDate",startData:s[1]},n.endTime={data:s[2]};break;default:}return n},"parseData"),Zt,St,U=[],Qe={},Li=l(function(t,e){let r={section:vt,type:vt,processed:!1,manualEndTime:!1,renderEndTime:null,raw:{data:e},task:t,classes:[]},s=$i(St,e);r.raw.startTime=s.startTime,r.raw.endTime=s.endTime,r.id=s.id,r.prevTaskId=St,r.active=s.active,r.done=s.done,r.crit=s.crit,r.milestone=s.milestone,r.vert=s.vert,r.vert?r.order=-1:(r.order=Xt,Xt++);let n=U.push(r);St=r.id,Qe[r.id]=n-1},"addTask"),lt=l(function(t){let e=Qe[t];return U[e]},"findTaskById"),Ai=l(function(t,e){let r={section:vt,type:vt,description:t,task:t,classes:[]},s=Yi(Zt,e);r.startTime=s.startTime,r.endTime=s.endTime,r.id=s.id,r.active=s.active,r.done=s.done,r.crit=s.crit,r.milestone=s.milestone,r.vert=s.vert,Zt=r,Ct.push(r)},"addTaskOrg"),Ve=l(function(){let t=l(function(r){let s=U[r],n="";switch(U[r].raw.startTime.type){case"prevTaskEnd":{let f=lt(s.prevTaskId);s.startTime=f.endTime;break}case"getStartDate":n=qt(void 0,et,U[r].raw.startTime.startData),n&&(U[r].startTime=n);break}return U[r].startTime&&(U[r].endTime=Ze(U[r].startTime,et,U[r].raw.endTime.data,bt),U[r].endTime&&(U[r].processed=!0,U[r].manualEndTime=(0,K.default)(U[r].raw.endTime.data,"YYYY-MM-DD",!0).isValid(),Ue(U[r],et,pt,gt))),U[r].processed},"compileTask"),e=!0;for(let[r,s]of U.entries())t(r),e=e&&s.processed;return e},"compileTasks"),Fi=l(function(t,e){let r=e;ct().securityLevel!=="loose"&&(r=(0,ze.sanitizeUrl)(e)),t.split(",").forEach(function(s){lt(s)!==void 0&&(Je(s,()=>{window.open(r,"_self")}),te.set(s,r))}),Ke(t,"clickable")},"setLink"),Ke=l(function(t,e){t.split(",").forEach(function(r){let s=lt(r);s!==void 0&&s.classes.push(e)})},"setClass"),Oi=l(function(t,e,r){if(ct().securityLevel!=="loose"||e===void 0)return;let s=[];if(typeof r=="string"){s=r.split(/,(?=(?:(?:[^"]*"){2})*[^"]*$)/);for(let f=0;f<s.length;f++){let y=s[f].trim();y.startsWith('"')&&y.endsWith('"')&&(y=y.substr(1,y.length-2)),s[f]=y}}s.length===0&&s.push(t),lt(t)!==void 0&&Je(t,()=>{$e.runFunc(e,...s)})},"setClickFun"),Je=l(function(t,e){se.push(function(){let r=mt?`${mt}-${t}`:t,s=document.querySelector(`[id="${r}"]`);s!==null&&s.addEventListener("click",function(){e()})},function(){let r=mt?`${mt}-${t}`:t,s=document.querySelector(`[id="${r}-text"]`);s!==null&&s.addEventListener("click",function(){e()})})},"pushFun"),Wi=l(function(t,e,r){t.split(",").forEach(function(s){Oi(s,e,r)}),Ke(t,"clickable")},"setClickEvent"),Vi=l(function(t){se.forEach(function(e){e(t)})},"bindFunctions"),Pi={getConfig:l(()=>ct().gantt,"getConfig"),clear:si,setDateFormat:di,getDateFormat:pi,enableInclusiveEndDates:fi,endDatesAreInclusive:hi,enableTopAxis:mi,topAxisEnabled:ki,setAxisFormat:ri,getAxisFormat:ai,setTickInterval:oi,getTickInterval:ci,setTodayMarker:li,getTodayMarker:ui,setAccTitle:fe,getAccTitle:he,setDiagramTitle:ye,getDiagramTitle:ge,setDiagramId:ni,setDisplayMode:yi,getDisplayMode:gi,setAccDescription:me,getAccDescription:ke,addSection:_i,getSections:Di,getTasks:Si,addTask:Li,findTaskById:lt,addTaskOrg:Ai,setIncludes:vi,getIncludes:Ti,setExcludes:bi,getExcludes:xi,setClickEvent:Wi,setLink:Fi,getLinks:wi,bindFunctions:Vi,parseDuration:qe,isInvalidDate:Ge,setWeekday:Ci,getWeekday:Mi,setWeekend:Ei};function ae(t,e,r){let s=!0;for(;s;)s=!1,r.forEach(function(n){let f="^\\s*"+n+"\\s*$",y=new RegExp(f);t[0].match(y)&&(e[n]=!0,t.shift(1),s=!0)})}D(ae,"getTaskTags");l(ae,"getTaskTags");kt.default.extend(ti.default);var zi=l(function(){tt.debug("Something is calling, setConf, remove the call")},"setConf"),Pe={monday:De,tuesday:Se,wednesday:Ce,thursday:Me,friday:Ee,saturday:Ie,sunday:_e},Ni=l((t,e)=>{let r=[...t].map(()=>-1/0),s=[...t].sort((f,y)=>f.startTime-y.startTime||f.order-y.order),n=0;for(let f of s)for(let y=0;y<r.length;y++)if(f.startTime>=r[y]){r[y]=f.endTime,f.order=y+e,y>n&&(n=y);break}return n},"getMaxIntersections"),rt,Gt=1e4,Ri=l(function(t,e,r,s){let n=ct().gantt;s.db.setDiagramId(e);let f=ct().securityLevel,y;f==="sandbox"&&(y=Tt("#i"+e));let _=f==="sandbox"?Tt(y.nodes()[0].contentDocument.body):Tt("body"),S=f==="sandbox"?y.nodes()[0].contentDocument:document,H=S.getElementById(e);rt=H.parentElement.offsetWidth,rt===void 0&&(rt=1200),n.useWidth!==void 0&&(rt=n.useWidth);let $=s.db.getTasks(),P=$.filter(k=>!k.vert),z=[];for(let k of P)z.push(k.type);z=O(z);let j={},b=2*n.topPadding;if(s.db.getDisplayMode()==="compact"||n.displayMode==="compact"){let k={};for(let v of P)k[v.section]===void 0?k[v.section]=[v]:k[v.section].push(v);let p=0;for(let v of Object.keys(k)){let T=Ni(k[v],p)+1;p+=T,b+=T*(n.barHeight+n.barGap),j[v]=T}}else{b+=P.length*(n.barHeight+n.barGap);for(let k of z)j[k]=P.filter(p=>p.type===k).length}H.setAttribute("viewBox","0 0 "+rt+" "+b);let w=_.select(`[id="${e}"]`),x=Ye().domain([ve($,function(k){return k.startTime}),pe($,function(k){return k.endTime})]).rangeRound([0,rt-n.leftPadding-n.rightPadding]);function F(k,p){let v=k.startTime,T=p.startTime,a=0;return v>T?a=1:v<T&&(a=-1),a}D(F,"taskCompare"),l(F,"taskCompare"),$.sort(F),X($,rt,b),ue(w,b,rt,n.useMaxWidth),w.append("text").text(s.db.getDiagramTitle()).attr("x",rt/2).attr("y",n.titleTopMargin).attr("class","titleText");function X(k,p,v){let T=n.barHeight,a=T+n.barGap,h=n.topPadding,d=n.leftPadding,u=we().domain([0,z.length]).range(["#00B9FA","#F95002"]).interpolate(xe);L(a,h,d,p,v,k,s.db.getExcludes(),s.db.getIncludes()),m(d,h,p,v),q(k,a,h,d,T,u,p,v),Y(a,h,d,T,u),V(d,h,p,v)}D(X,"makeGantt"),l(X,"makeGantt");function q(k,p,v,T,a,h,d){k.sort((o,C)=>o.vert===C.vert?0:o.vert?1:-1);let u=k.filter(o=>!o.vert),i=[...new Set(u.map(o=>o.order))].map(o=>u.find(C=>C.order===o));w.append("g").selectAll("rect").data(i).enter().append("rect").attr("x",0).attr("y",function(o,C){return C=o.order,C*p+v-2}).attr("width",function(){return d-n.rightPadding/2}).attr("height",p).attr("class",function(o){for(let[C,I]of z.entries())if(o.type===I)return"section section"+C%n.numberSectionStyles;return"section section0"}).enter();let E=w.append("g").selectAll("rect").data(k).enter(),c=s.db.getLinks();if(E.append("rect").attr("id",function(o){return e+"-"+o.id}).attr("rx",3).attr("ry",3).attr("x",function(o){return o.milestone?x(o.startTime)+T+.5*(x(o.endTime)-x(o.startTime))-.5*a:x(o.startTime)+T}).attr("y",function(o,C){return C=o.order,o.vert?n.gridLineStartPadding:C*p+v}).attr("width",function(o){return o.milestone?a:o.vert?.08*a:x(o.renderEndTime||o.endTime)-x(o.startTime)}).attr("height",function(o){return o.vert?u.length*(n.barHeight+n.barGap)+n.barHeight*2:a}).attr("transform-origin",function(o,C){return C=o.order,(x(o.startTime)+T+.5*(x(o.endTime)-x(o.startTime))).toString()+"px "+(C*p+v+.5*a).toString()+"px"}).attr("class",function(o){let C="task",I="";o.classes.length>0&&(I=o.classes.join(" "));let N=0;for(let[A,W]of z.entries())o.type===W&&(N=A%n.numberSectionStyles);let R="";return o.active?o.crit?R+=" activeCrit":R=" active":o.done?o.crit?R=" doneCrit":R=" done":o.crit&&(R+=" crit"),R.length===0&&(R=" task"),o.milestone&&(R=" milestone "+R),o.vert&&(R=" vert "+R),R+=N,R+=" "+I,C+R}),E.append("text").attr("id",function(o){return e+"-"+o.id+"-text"}).text(function(o){return o.task}).attr("font-size",n.fontSize).attr("x",function(o){let C=x(o.startTime),I=x(o.renderEndTime||o.endTime);if(o.milestone&&(C+=.5*(x(o.endTime)-x(o.startTime))-.5*a,I=C+a),o.vert)return x(o.startTime)+T;let N=this.getBBox().width;return N>I-C?I+N+1.5*n.leftPadding>d?C+T-5:I+T+5:(I-C)/2+C+T}).attr("y",function(o,C){return o.vert?n.gridLineStartPadding+u.length*(n.barHeight+n.barGap)+60:(C=o.order,C*p+n.barHeight/2+(n.fontSize/2-2)+v)}).attr("text-height",a).attr("class",function(o){let C=x(o.startTime),I=x(o.endTime);o.milestone&&(I=C+a);let N=this.getBBox().width,R="";o.classes.length>0&&(R=o.classes.join(" "));let A=0;for(let[it,st]of z.entries())o.type===st&&(A=it%n.numberSectionStyles);let W="";return o.active&&(o.crit?W="activeCritText"+A:W="activeText"+A),o.done?o.crit?W=W+" doneCritText"+A:W=W+" doneText"+A:o.crit&&(W=W+" critText"+A),o.milestone&&(W+=" milestoneText"),o.vert&&(W+=" vertText"),N>I-C?I+N+1.5*n.leftPadding>d?R+" taskTextOutsideLeft taskTextOutside"+A+" "+W:R+" taskTextOutsideRight taskTextOutside"+A+" "+W+" width-"+N:R+" taskText taskText"+A+" "+W+" width-"+N}),ct().securityLevel==="sandbox"){let o;o=Tt("#i"+e);let C=o.nodes()[0].contentDocument;E.filter(function(I){return c.has(I.id)}).each(function(I){var N=C.querySelector("#"+CSS.escape(e+"-"+I.id)),R=C.querySelector("#"+CSS.escape(e+"-"+I.id+"-text"));let A=N.parentNode;var W=C.createElement("a");W.setAttribute("xlink:href",c.get(I.id)),W.setAttribute("target","_top"),A.appendChild(W),W.appendChild(N),W.appendChild(R)})}}D(q,"drawRects"),l(q,"drawRects");function L(k,p,v,T,a,h,d,u){if(d.length===0&&u.length===0)return;let M,i;for(let{startTime:I,endTime:N}of h)(M===void 0||I<M)&&(M=I),(i===void 0||N>i)&&(i=N);if(!M||!i)return;if((0,kt.default)(i).diff((0,kt.default)(M),"year")>5){tt.warn("The difference between the min and max time is more than 5 years. This will cause performance issues. Skipping drawing exclude days.");return}let E=s.db.getDateFormat(),c=[],B=null,o=(0,kt.default)(M);for(;o.valueOf()<=i;)s.db.isInvalidDate(o,E,d,u)?B?B.end=o:B={start:o,end:o}:B&&(c.push(B),B=null),o=o.add(1,"d");w.append("g").selectAll("rect").data(c).enter().append("rect").attr("id",I=>e+"-exclude-"+I.start.format("YYYY-MM-DD")).attr("x",I=>x(I.start.startOf("day"))+v).attr("y",n.gridLineStartPadding).attr("width",I=>x(I.end.endOf("day"))-x(I.start.startOf("day"))).attr("height",a-p-n.gridLineStartPadding).attr("transform-origin",function(I,N){return(x(I.start)+v+.5*(x(I.end)-x(I.start))).toString()+"px "+(N*k+.5*a).toString()+"px"}).attr("class","exclude-range")}D(L,"drawExcludeDays"),l(L,"drawExcludeDays");function g(k,p,v,T){if(v<=0||k>p)return 1/0;let a=p-k,h=kt.default.duration({[T??"day"]:v}).asMilliseconds();return h<=0?1/0:Math.ceil(a/h)}D(g,"getEstimatedTickCount"),l(g,"getEstimatedTickCount");function m(k,p,v,T){let a=s.db.getDateFormat(),h=s.db.getAxisFormat(),d;h?d=h:a==="D"?d="%d":d=n.axisFormat??"%Y-%m-%d";let u=be(x).tickSize(-T+p+n.gridLineStartPadding).tickFormat(Wt(d)),i=/^([1-9]\d*)(millisecond|second|minute|hour|day|week|month)$/.exec(s.db.getTickInterval()||n.tickInterval);if(i!==null){let E=parseInt(i[1],10);if(isNaN(E)||E<=0)tt.warn(`Invalid tick interval value: "${i[1]}". Skipping custom tick interval.`);else{let c=i[2],B=s.db.getWeekday()||n.weekday,o=x.domain(),C=o[0],I=o[1],N=g(C,I,E,c);if(N>Gt)tt.warn(`The tick interval "${E}${c}" would generate ${N} ticks, which exceeds the maximum allowed (${Gt}). This may indicate an invalid date or time range. Skipping custom tick interval.`);else switch(c){case"millisecond":u.ticks(Yt.every(E));break;case"second":u.ticks($t.every(E));break;case"minute":u.ticks(Lt.every(E));break;case"hour":u.ticks(At.every(E));break;case"day":u.ticks(Ft.every(E));break;case"week":u.ticks(Pe[B].every(E));break;case"month":u.ticks(Ot.every(E));break}}}if(w.append("g").attr("class","grid").attr("transform","translate("+k+", "+(T-50)+")").call(u).selectAll("text").style("text-anchor","middle").attr("fill","#000").attr("stroke","none").attr("font-size",10).attr("dy","1em"),s.db.topAxisEnabled()||n.topAxis){let E=Te(x).tickSize(-T+p+n.gridLineStartPadding).tickFormat(Wt(d));if(i!==null){let c=parseInt(i[1],10);if(isNaN(c)||c<=0)tt.warn(`Invalid tick interval value: "${i[1]}". Skipping custom tick interval.`);else{let B=i[2],o=s.db.getWeekday()||n.weekday,C=x.domain(),I=C[0],N=C[1];if(g(I,N,c,B)<=Gt)switch(B){case"millisecond":E.ticks(Yt.every(c));break;case"second":E.ticks($t.every(c));break;case"minute":E.ticks(Lt.every(c));break;case"hour":E.ticks(At.every(c));break;case"day":E.ticks(Ft.every(c));break;case"week":E.ticks(Pe[o].every(c));break;case"month":E.ticks(Ot.every(c));break}}}w.append("g").attr("class","grid").attr("transform","translate("+k+", "+p+")").call(E).selectAll("text").style("text-anchor","middle").attr("fill","#000").attr("stroke","none").attr("font-size",10)}}D(m,"makeGrid"),l(m,"makeGrid");function Y(k,p){let v=0,T=Object.keys(j).map(a=>[a,j[a]]);w.append("g").selectAll("text").data(T).enter().append(function(a){let h=a[0].split(le.lineBreakRegex),d=-(h.length-1)/2,u=S.createElementNS("http://www.w3.org/2000/svg","text");u.setAttribute("dy",d+"em");for(let[M,i]of h.entries()){let E=S.createElementNS("http://www.w3.org/2000/svg","tspan");E.setAttribute("alignment-baseline","central"),E.setAttribute("x","10"),M>0&&E.setAttribute("dy","1em"),E.textContent=i,u.appendChild(E)}return u}).attr("x",10).attr("y",function(a,h){if(h>0)for(let d=0;d<h;d++)return v+=T[h-1][1],a[1]*k/2+v*k+p;else return a[1]*k/2+p}).attr("font-size",n.sectionFontSize).attr("class",function(a){for(let[h,d]of z.entries())if(a[0]===d)return"sectionTitle sectionTitle"+h%n.numberSectionStyles;return"sectionTitle"})}D(Y,"vertLabels"),l(Y,"vertLabels");function V(k,p,v,T){let a=s.db.getTodayMarker();if(a==="off")return;let h=w.append("g").attr("class","today"),d=new Date,u=h.append("line");u.attr("x1",x(d)+k).attr("x2",x(d)+k).attr("y1",n.titleTopMargin).attr("y2",T-n.titleTopMargin).attr("class","today"),a!==""&&u.attr("style",a.replace(/,/g,";"))}D(V,"drawToday"),l(V,"drawToday");function O(k){let p={},v=[];for(let T=0,a=k.length;T<a;++T)Object.prototype.hasOwnProperty.call(p,k[T])||(p[k[T]]=!0,v.push(k[T]));return v}D(O,"checkUnique"),l(O,"checkUnique")},"draw"),Hi={setConf:zi,draw:Ri},ji=l(t=>`
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
`,"getStyles"),Bi=ji,es={parser:ii,db:Pi,renderer:Hi,styles:Bi};export{es as diagram};
//# sourceMappingURL=ganttDiagram-R7GPNTJY-RH73Y22P.js.map
