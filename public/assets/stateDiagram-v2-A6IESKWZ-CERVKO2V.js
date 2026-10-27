import{h as qt}from"./chunk-S557JKW5.js";import{a as Qt}from"./chunk-KREFWNNR.js";import{b as Xt,c as Jt}from"./chunk-FLDJAMEP.js";import"./chunk-COE4EHOP.js";import{a as Zt}from"./chunk-LHDTGR4U.js";import"./chunk-3TISWWHG.js";import"./chunk-A7W2J7A3.js";import"./chunk-BKEGFEZL.js";import"./chunk-2YTAJNVI.js";import"./chunk-G52VWKVS.js";import"./chunk-7UMRUUL7.js";import{b as Ut,c as Ht,d as zt,e as Kt}from"./chunk-NYITX7A4.js";import"./chunk-XVDWRHYI.js";import"./chunk-YHHEUHQR.js";import{g as Wt,q as jt}from"./chunk-FHCCFWZU.js";import"./chunk-4BVL6VY4.js";import{N as W,T as Ft,U as Pt,V as Bt,W as Gt,X as Yt,Y as Vt,Z as Mt,_ as F}from"./chunk-UEGXJBAQ.js";import{b as E,h as gt}from"./chunk-6DTRDB76.js";import{a as u}from"./chunk-Y43I3YIM.js";import{a as $t}from"./chunk-GY7RRZSA.js";import{a as T}from"./chunk-N5O52Q2M.js";var xt=(function(){var t=u(function(M,o,d,i){for(d=d||{},i=M.length;i--;d[M[i]]=o);return d},"o"),e=[1,2],n=[1,3],s=[1,4],c=[2,4],h=[1,9],p=[1,11],S=[1,16],a=[1,17],m=[1,18],b=[1,19],L=[1,33],w=[1,20],R=[1,21],D=[1,22],f=[1,23],I=[1,24],C=[1,26],P=[1,27],N=[1,28],B=[1,29],v=[1,30],z=[1,31],at=[1,32],nt=[1,35],ot=[1,36],lt=[1,37],ct=[1,38],K=[1,34],g=[1,4,5,16,17,19,21,22,24,25,26,27,28,29,33,35,37,38,41,45,48,51,52,53,54,57],ht=[1,4,5,14,15,16,17,19,21,22,24,25,26,27,28,29,33,35,37,38,39,40,41,45,48,51,52,53,54,57],Nt=[4,5,16,17,19,21,22,24,25,26,27,28,29,33,35,37,38,41,45,48,51,52,53,54,57],kt={trace:u(T(function(){},"trace"),"trace"),yy:{},symbols_:{error:2,start:3,SPACE:4,NL:5,SD:6,document:7,line:8,statement:9,classDefStatement:10,styleStatement:11,cssClassStatement:12,idStatement:13,DESCR:14,"-->":15,HIDE_EMPTY:16,scale:17,WIDTH:18,COMPOSIT_STATE:19,STRUCT_START:20,STRUCT_STOP:21,STATE_DESCR:22,AS:23,ID:24,FORK:25,JOIN:26,CHOICE:27,CONCURRENT:28,note:29,notePosition:30,NOTE_TEXT:31,direction:32,acc_title:33,acc_title_value:34,acc_descr:35,acc_descr_value:36,acc_descr_multiline_value:37,CLICK:38,STRING:39,HREF:40,classDef:41,CLASSDEF_ID:42,CLASSDEF_STYLEOPTS:43,DEFAULT:44,style:45,STYLE_IDS:46,STYLEDEF_STYLEOPTS:47,class:48,CLASSENTITY_IDS:49,STYLECLASS:50,direction_tb:51,direction_bt:52,direction_rl:53,direction_lr:54,eol:55,";":56,EDGE_STATE:57,STYLE_SEPARATOR:58,left_of:59,right_of:60,$accept:0,$end:1},terminals_:{2:"error",4:"SPACE",5:"NL",6:"SD",14:"DESCR",15:"-->",16:"HIDE_EMPTY",17:"scale",18:"WIDTH",19:"COMPOSIT_STATE",20:"STRUCT_START",21:"STRUCT_STOP",22:"STATE_DESCR",23:"AS",24:"ID",25:"FORK",26:"JOIN",27:"CHOICE",28:"CONCURRENT",29:"note",31:"NOTE_TEXT",33:"acc_title",34:"acc_title_value",35:"acc_descr",36:"acc_descr_value",37:"acc_descr_multiline_value",38:"CLICK",39:"STRING",40:"HREF",41:"classDef",42:"CLASSDEF_ID",43:"CLASSDEF_STYLEOPTS",44:"DEFAULT",45:"style",46:"STYLE_IDS",47:"STYLEDEF_STYLEOPTS",48:"class",49:"CLASSENTITY_IDS",50:"STYLECLASS",51:"direction_tb",52:"direction_bt",53:"direction_rl",54:"direction_lr",56:";",57:"EDGE_STATE",58:"STYLE_SEPARATOR",59:"left_of",60:"right_of"},productions_:[0,[3,2],[3,2],[3,2],[7,0],[7,2],[8,2],[8,1],[8,1],[9,1],[9,1],[9,1],[9,1],[9,2],[9,3],[9,4],[9,1],[9,2],[9,1],[9,4],[9,3],[9,6],[9,1],[9,1],[9,1],[9,1],[9,4],[9,4],[9,1],[9,2],[9,2],[9,1],[9,5],[9,5],[10,3],[10,3],[11,3],[12,3],[32,1],[32,1],[32,1],[32,1],[55,1],[55,1],[13,1],[13,1],[13,3],[13,3],[30,1],[30,1]],performAction:u(T(function(o,d,i,y,k,r,X){var l=r.length-1;switch(k){case 3:return y.setRootDoc(r[l]),r[l];break;case 4:this.$=[];break;case 5:r[l]!="nl"&&(r[l-1].push(r[l]),this.$=r[l-1]);break;case 6:case 7:this.$=r[l];break;case 8:this.$="nl";break;case 12:this.$=r[l];break;case 13:let ut=r[l-1];ut.description=y.trimColon(r[l]),this.$=ut;break;case 14:this.$={stmt:"relation",state1:r[l-2],state2:r[l]};break;case 15:let ft=y.trimColon(r[l]);this.$={stmt:"relation",state1:r[l-3],state2:r[l-1],description:ft};break;case 19:this.$={stmt:"state",id:r[l-3],type:"default",description:"",doc:r[l-1]};break;case 20:var G=r[l],Y=r[l-2].trim();if(r[l].match(":")){var Z=r[l].split(":");G=Z[0],Y=[Y,Z[1]]}this.$={stmt:"state",id:G,type:"default",description:Y};break;case 21:this.$={stmt:"state",id:r[l-3],type:"default",description:r[l-5],doc:r[l-1]};break;case 22:this.$={stmt:"state",id:r[l],type:"fork"};break;case 23:this.$={stmt:"state",id:r[l],type:"join"};break;case 24:this.$={stmt:"state",id:r[l],type:"choice"};break;case 25:this.$={stmt:"state",id:y.getDividerId(),type:"divider"};break;case 26:this.$={stmt:"state",id:r[l-1].trim(),note:{position:r[l-2].trim(),text:r[l].trim()}};break;case 29:this.$=r[l].trim(),y.setAccTitle(this.$);break;case 30:case 31:this.$=r[l].trim(),y.setAccDescription(this.$);break;case 32:this.$={stmt:"click",id:r[l-3],url:r[l-2],tooltip:r[l-1]};break;case 33:this.$={stmt:"click",id:r[l-3],url:r[l-1],tooltip:""};break;case 34:case 35:this.$={stmt:"classDef",id:r[l-1].trim(),classes:r[l].trim()};break;case 36:this.$={stmt:"style",id:r[l-1].trim(),styleClass:r[l].trim()};break;case 37:this.$={stmt:"applyClass",id:r[l-1].trim(),styleClass:r[l].trim()};break;case 38:y.setDirection("TB"),this.$={stmt:"dir",value:"TB"};break;case 39:y.setDirection("BT"),this.$={stmt:"dir",value:"BT"};break;case 40:y.setDirection("RL"),this.$={stmt:"dir",value:"RL"};break;case 41:y.setDirection("LR"),this.$={stmt:"dir",value:"LR"};break;case 44:case 45:this.$={stmt:"state",id:r[l].trim(),type:"default",description:""};break;case 46:this.$={stmt:"state",id:r[l-2].trim(),classes:[r[l].trim()],type:"default",description:""};break;case 47:this.$={stmt:"state",id:r[l-2].trim(),classes:[r[l].trim()],type:"default",description:""};break}},"anonymous"),"anonymous"),table:[{3:1,4:e,5:n,6:s},{1:[3]},{3:5,4:e,5:n,6:s},{3:6,4:e,5:n,6:s},t([1,4,5,16,17,19,22,24,25,26,27,28,29,33,35,37,38,41,45,48,51,52,53,54,57],c,{7:7}),{1:[2,1]},{1:[2,2]},{1:[2,3],4:h,5:p,8:8,9:10,10:12,11:13,12:14,13:15,16:S,17:a,19:m,22:b,24:L,25:w,26:R,27:D,28:f,29:I,32:25,33:C,35:P,37:N,38:B,41:v,45:z,48:at,51:nt,52:ot,53:lt,54:ct,57:K},t(g,[2,5]),{9:39,10:12,11:13,12:14,13:15,16:S,17:a,19:m,22:b,24:L,25:w,26:R,27:D,28:f,29:I,32:25,33:C,35:P,37:N,38:B,41:v,45:z,48:at,51:nt,52:ot,53:lt,54:ct,57:K},t(g,[2,7]),t(g,[2,8]),t(g,[2,9]),t(g,[2,10]),t(g,[2,11]),t(g,[2,12],{14:[1,40],15:[1,41]}),t(g,[2,16]),{18:[1,42]},t(g,[2,18],{20:[1,43]}),{23:[1,44]},t(g,[2,22]),t(g,[2,23]),t(g,[2,24]),t(g,[2,25]),{30:45,31:[1,46],59:[1,47],60:[1,48]},t(g,[2,28]),{34:[1,49]},{36:[1,50]},t(g,[2,31]),{13:51,24:L,57:K},{42:[1,52],44:[1,53]},{46:[1,54]},{49:[1,55]},t(ht,[2,44],{58:[1,56]}),t(ht,[2,45],{58:[1,57]}),t(g,[2,38]),t(g,[2,39]),t(g,[2,40]),t(g,[2,41]),t(g,[2,6]),t(g,[2,13]),{13:58,24:L,57:K},t(g,[2,17]),t(Nt,c,{7:59}),{24:[1,60]},{24:[1,61]},{23:[1,62]},{24:[2,48]},{24:[2,49]},t(g,[2,29]),t(g,[2,30]),{39:[1,63],40:[1,64]},{43:[1,65]},{43:[1,66]},{47:[1,67]},{50:[1,68]},{24:[1,69]},{24:[1,70]},t(g,[2,14],{14:[1,71]}),{4:h,5:p,8:8,9:10,10:12,11:13,12:14,13:15,16:S,17:a,19:m,21:[1,72],22:b,24:L,25:w,26:R,27:D,28:f,29:I,32:25,33:C,35:P,37:N,38:B,41:v,45:z,48:at,51:nt,52:ot,53:lt,54:ct,57:K},t(g,[2,20],{20:[1,73]}),{31:[1,74]},{24:[1,75]},{39:[1,76]},{39:[1,77]},t(g,[2,34]),t(g,[2,35]),t(g,[2,36]),t(g,[2,37]),t(ht,[2,46]),t(ht,[2,47]),t(g,[2,15]),t(g,[2,19]),t(Nt,c,{7:78}),t(g,[2,26]),t(g,[2,27]),{5:[1,79]},{5:[1,80]},{4:h,5:p,8:8,9:10,10:12,11:13,12:14,13:15,16:S,17:a,19:m,21:[1,81],22:b,24:L,25:w,26:R,27:D,28:f,29:I,32:25,33:C,35:P,37:N,38:B,41:v,45:z,48:at,51:nt,52:ot,53:lt,54:ct,57:K},t(g,[2,32]),t(g,[2,33]),t(g,[2,21])],defaultActions:{5:[2,1],6:[2,2],47:[2,48],48:[2,49]},parseError:u(T(function(o,d){if(d.recoverable)this.trace(o);else{var i=new Error(o);throw i.hash=d,i}},"parseError"),"parseError"),parse:u(T(function(o){var d=this,i=[0],y=[],k=[null],r=[],X=this.table,l="",G=0,Y=0,Z=0,ut=2,ft=1,Te=r.slice.call(arguments,1),_=Object.create(this.lexer),U={yy:{}};for(var Tt in this.yy)Object.prototype.hasOwnProperty.call(this.yy,Tt)&&(U.yy[Tt]=this.yy[Tt]);_.setInput(o,U.yy),U.yy.lexer=_,U.yy.parser=this,typeof _.yylloc>"u"&&(_.yylloc={});var Et=_.yylloc;r.push(Et);var Ee=_.options&&_.options.ranges;typeof U.yy.parseError=="function"?this.parseError=U.yy.parseError:this.parseError=Object.getPrototypeOf(this).parseError;function _e(O){i.length=i.length-2*O,k.length=k.length-O,r.length=r.length-O}T(_e,"popStack"),u(_e,"popStack");function Ot(){var O;return O=y.pop()||_.lex()||ft,typeof O!="number"&&(O instanceof Array&&(y=O,O=y.pop()),O=d.symbols_[O]||O),O}T(Ot,"lex"),u(Ot,"lex");for(var A,_t,H,$,es,vt,J={},pt,V,Rt,St;;){if(H=i[i.length-1],this.defaultActions[H]?$=this.defaultActions[H]:((A===null||typeof A>"u")&&(A=Ot()),$=X[H]&&X[H][A]),typeof $>"u"||!$.length||!$[0]){var Dt="";St=[];for(pt in X[H])this.terminals_[pt]&&pt>ut&&St.push("'"+this.terminals_[pt]+"'");_.showPosition?Dt="Parse error on line "+(G+1)+`:
`+_.showPosition()+`
Expecting `+St.join(", ")+", got '"+(this.terminals_[A]||A)+"'":Dt="Parse error on line "+(G+1)+": Unexpected "+(A==ft?"end of input":"'"+(this.terminals_[A]||A)+"'"),this.parseError(Dt,{text:_.match,token:this.terminals_[A]||A,line:_.yylineno,loc:Et,expected:St})}if($[0]instanceof Array&&$.length>1)throw new Error("Parse Error: multiple actions possible at state: "+H+", token: "+A);switch($[0]){case 1:i.push(A),k.push(_.yytext),r.push(_.yylloc),i.push($[1]),A=null,_t?(A=_t,_t=null):(Y=_.yyleng,l=_.yytext,G=_.yylineno,Et=_.yylloc,Z>0&&Z--);break;case 2:if(V=this.productions_[$[1]][1],J.$=k[k.length-V],J._$={first_line:r[r.length-(V||1)].first_line,last_line:r[r.length-1].last_line,first_column:r[r.length-(V||1)].first_column,last_column:r[r.length-1].last_column},Ee&&(J._$.range=[r[r.length-(V||1)].range[0],r[r.length-1].range[1]]),vt=this.performAction.apply(J,[l,Y,G,U.yy,$[1],k,r].concat(Te)),typeof vt<"u")return vt;V&&(i=i.slice(0,-1*V*2),k=k.slice(0,-1*V),r=r.slice(0,-1*V)),i.push(this.productions_[$[1]][0]),k.push(J.$),r.push(J._$),Rt=X[i[i.length-2]][i[i.length-1]],i.push(Rt);break;case 3:return!0}}return!0},"parse"),"parse")},ke=(function(){var M={EOF:1,parseError:u(T(function(d,i){if(this.yy.parser)this.yy.parser.parseError(d,i);else throw new Error(d)},"parseError"),"parseError"),setInput:u(function(o,d){return this.yy=d||this.yy||{},this._input=o,this._more=this._backtrack=this.done=!1,this.yylineno=this.yyleng=0,this.yytext=this.matched=this.match="",this.conditionStack=["INITIAL"],this.yylloc={first_line:1,first_column:0,last_line:1,last_column:0},this.options.ranges&&(this.yylloc.range=[0,0]),this.offset=0,this},"setInput"),input:u(function(){var o=this._input[0];this.yytext+=o,this.yyleng++,this.offset++,this.match+=o,this.matched+=o;var d=o.match(/(?:\r\n?|\n).*/g);return d?(this.yylineno++,this.yylloc.last_line++):this.yylloc.last_column++,this.options.ranges&&this.yylloc.range[1]++,this._input=this._input.slice(1),o},"input"),unput:u(function(o){var d=o.length,i=o.split(/(?:\r\n?|\n)/g);this._input=o+this._input,this.yytext=this.yytext.substr(0,this.yytext.length-d),this.offset-=d;var y=this.match.split(/(?:\r\n?|\n)/g);this.match=this.match.substr(0,this.match.length-1),this.matched=this.matched.substr(0,this.matched.length-1),i.length-1&&(this.yylineno-=i.length-1);var k=this.yylloc.range;return this.yylloc={first_line:this.yylloc.first_line,last_line:this.yylineno+1,first_column:this.yylloc.first_column,last_column:i?(i.length===y.length?this.yylloc.first_column:0)+y[y.length-i.length].length-i[0].length:this.yylloc.first_column-d},this.options.ranges&&(this.yylloc.range=[k[0],k[0]+this.yyleng-d]),this.yyleng=this.yytext.length,this},"unput"),more:u(function(){return this._more=!0,this},"more"),reject:u(function(){if(this.options.backtrack_lexer)this._backtrack=!0;else return this.parseError("Lexical error on line "+(this.yylineno+1)+`. You can only invoke reject() in the lexer when the lexer is of the backtracking persuasion (options.backtrack_lexer = true).
`+this.showPosition(),{text:"",token:null,line:this.yylineno});return this},"reject"),less:u(function(o){this.unput(this.match.slice(o))},"less"),pastInput:u(function(){var o=this.matched.substr(0,this.matched.length-this.match.length);return(o.length>20?"...":"")+o.substr(-20).replace(/\n/g,"")},"pastInput"),upcomingInput:u(function(){var o=this.match;return o.length<20&&(o+=this._input.substr(0,20-o.length)),(o.substr(0,20)+(o.length>20?"...":"")).replace(/\n/g,"")},"upcomingInput"),showPosition:u(function(){var o=this.pastInput(),d=new Array(o.length+1).join("-");return o+this.upcomingInput()+`
`+d+"^"},"showPosition"),test_match:u(function(o,d){var i,y,k;if(this.options.backtrack_lexer&&(k={yylineno:this.yylineno,yylloc:{first_line:this.yylloc.first_line,last_line:this.last_line,first_column:this.yylloc.first_column,last_column:this.yylloc.last_column},yytext:this.yytext,match:this.match,matches:this.matches,matched:this.matched,yyleng:this.yyleng,offset:this.offset,_more:this._more,_input:this._input,yy:this.yy,conditionStack:this.conditionStack.slice(0),done:this.done},this.options.ranges&&(k.yylloc.range=this.yylloc.range.slice(0))),y=o[0].match(/(?:\r\n?|\n).*/g),y&&(this.yylineno+=y.length),this.yylloc={first_line:this.yylloc.last_line,last_line:this.yylineno+1,first_column:this.yylloc.last_column,last_column:y?y[y.length-1].length-y[y.length-1].match(/\r?\n?/)[0].length:this.yylloc.last_column+o[0].length},this.yytext+=o[0],this.match+=o[0],this.matches=o,this.yyleng=this.yytext.length,this.options.ranges&&(this.yylloc.range=[this.offset,this.offset+=this.yyleng]),this._more=!1,this._backtrack=!1,this._input=this._input.slice(o[0].length),this.matched+=o[0],i=this.performAction.call(this,this.yy,this,d,this.conditionStack[this.conditionStack.length-1]),this.done&&this._input&&(this.done=!1),i)return i;if(this._backtrack){for(var r in k)this[r]=k[r];return!1}return!1},"test_match"),next:u(function(){if(this.done)return this.EOF;this._input||(this.done=!0);var o,d,i,y;this._more||(this.yytext="",this.match="");for(var k=this._currentRules(),r=0;r<k.length;r++)if(i=this._input.match(this.rules[k[r]]),i&&(!d||i[0].length>d[0].length)){if(d=i,y=r,this.options.backtrack_lexer){if(o=this.test_match(i,k[r]),o!==!1)return o;if(this._backtrack){d=!1;continue}else return!1}else if(!this.options.flex)break}return d?(o=this.test_match(d,k[y]),o!==!1?o:!1):this._input===""?this.EOF:this.parseError("Lexical error on line "+(this.yylineno+1)+`. Unrecognized text.
`+this.showPosition(),{text:"",token:null,line:this.yylineno})},"next"),lex:u(T(function(){var d=this.next();return d||this.lex()},"lex"),"lex"),begin:u(T(function(d){this.conditionStack.push(d)},"begin"),"begin"),popState:u(T(function(){var d=this.conditionStack.length-1;return d>0?this.conditionStack.pop():this.conditionStack[0]},"popState"),"popState"),_currentRules:u(T(function(){return this.conditionStack.length&&this.conditionStack[this.conditionStack.length-1]?this.conditions[this.conditionStack[this.conditionStack.length-1]].rules:this.conditions.INITIAL.rules},"_currentRules"),"_currentRules"),topState:u(T(function(d){return d=this.conditionStack.length-1-Math.abs(d||0),d>=0?this.conditionStack[d]:"INITIAL"},"topState"),"topState"),pushState:u(T(function(d){this.begin(d)},"pushState"),"pushState"),stateStackSize:u(T(function(){return this.conditionStack.length},"stateStackSize"),"stateStackSize"),options:{"case-insensitive":!0},performAction:u(T(function(d,i,y,k){function r(){let l=i.yytext.indexOf("%%");if(l===0)return!1;if(l>0){let G=i.yytext.slice(0,l),Y=i.yytext.slice(l);Y&&d.lexer.unput(Y),i.yytext=G}return!0}T(r,"processId"),u(r,"processId");var X=k;switch(y){case 0:return 38;case 1:return 40;case 2:return 39;case 3:return 44;case 4:return 51;case 5:return 52;case 6:return 53;case 7:return 54;case 8:return 5;case 9:break;case 10:break;case 11:break;case 12:break;case 13:return this.pushState("SCALE"),17;break;case 14:return 18;case 15:this.popState();break;case 16:return this.begin("acc_title"),33;break;case 17:return this.popState(),"acc_title_value";break;case 18:return this.begin("acc_descr"),35;break;case 19:return this.popState(),"acc_descr_value";break;case 20:this.begin("acc_descr_multiline");break;case 21:this.popState();break;case 22:return"acc_descr_multiline_value";case 23:return this.pushState("CLASSDEF"),41;break;case 24:return this.popState(),this.pushState("CLASSDEFID"),"DEFAULT_CLASSDEF_ID";break;case 25:return this.popState(),this.pushState("CLASSDEFID"),42;break;case 26:return this.popState(),43;break;case 27:return this.pushState("CLASS"),48;break;case 28:return this.popState(),this.pushState("CLASS_STYLE"),49;break;case 29:return this.popState(),50;break;case 30:return this.pushState("STYLE"),45;break;case 31:return this.popState(),this.pushState("STYLEDEF_STYLES"),46;break;case 32:return this.popState(),47;break;case 33:return this.pushState("SCALE"),17;break;case 34:return 18;case 35:this.popState();break;case 36:this.pushState("STATE");break;case 37:return this.popState(),i.yytext=i.yytext.slice(0,-8).trim(),25;break;case 38:return this.popState(),i.yytext=i.yytext.slice(0,-8).trim(),26;break;case 39:return this.popState(),i.yytext=i.yytext.slice(0,-10).trim(),27;break;case 40:return this.popState(),i.yytext=i.yytext.slice(0,-8).trim(),25;break;case 41:return this.popState(),i.yytext=i.yytext.slice(0,-8).trim(),26;break;case 42:return this.popState(),i.yytext=i.yytext.slice(0,-10).trim(),27;break;case 43:return 51;case 44:return 52;case 45:return 53;case 46:return 54;case 47:this.pushState("STATE_STRING");break;case 48:return this.pushState("STATE_ID"),"AS";break;case 49:if(!r())return;return this.popState(),"ID";break;case 50:this.popState();break;case 51:return"STATE_DESCR";case 52:throw new Error('Error: State name must be a single word. Found: "'+i.yytext.trim()+'"');case 53:return 19;case 54:this.popState();break;case 55:return this.popState(),this.pushState("struct"),20;break;case 56:return this.popState(),21;break;case 57:break;case 58:return this.begin("NOTE"),29;break;case 59:return this.popState(),this.pushState("NOTE_ID"),59;break;case 60:return this.popState(),this.pushState("NOTE_ID"),60;break;case 61:this.popState(),this.pushState("FLOATING_NOTE");break;case 62:return this.popState(),this.pushState("FLOATING_NOTE_ID"),"AS";break;case 63:break;case 64:return"NOTE_TEXT";case 65:if(!r())return;return this.popState(),"ID";break;case 66:if(!r())return;return this.popState(),this.pushState("NOTE_TEXT"),24;break;case 67:return this.popState(),i.yytext=i.yytext.substr(2).trim(),31;break;case 68:return this.popState(),i.yytext=i.yytext.slice(0,-8).trim(),31;break;case 69:return 6;case 70:return 6;case 71:return 16;case 72:return 57;case 73:return r()?24:void 0;case 74:return i.yytext=i.yytext.trim(),14;break;case 75:return 15;case 76:return 28;case 77:return 58;case 78:return 5;case 79:return"INVALID"}},"anonymous"),"anonymous"),rules:[/^(?:click\b)/i,/^(?:href\b)/i,/^(?:"[^"]*")/i,/^(?:default\b)/i,/^(?:.*direction\s+TB[^\n]*)/i,/^(?:.*direction\s+BT[^\n]*)/i,/^(?:.*direction\s+RL[^\n]*)/i,/^(?:.*direction\s+LR[^\n]*)/i,/^(?:[\n]+)/i,/^(?:[\s]+)/i,/^(?:((?!\n)\s)+)/i,/^(?:#[^\n]*)/i,/^(?:%%(?!\{)[^\n]*)/i,/^(?:scale\s+)/i,/^(?:\d+)/i,/^(?:\s+width\b)/i,/^(?:accTitle\s*:\s*)/i,/^(?:(?!\n||)*[^\n]*)/i,/^(?:accDescr\s*:\s*)/i,/^(?:(?!\n||)*[^\n]*)/i,/^(?:accDescr\s*\{\s*)/i,/^(?:[\}])/i,/^(?:[^\}]*)/i,/^(?:classDef\s+)/i,/^(?:DEFAULT\s+)/i,/^(?:\w+\s+)/i,/^(?:[^\n]*)/i,/^(?:class\s+)/i,/^(?:(\w+)+((,\s*\w+)*))/i,/^(?:[^\n]*)/i,/^(?:style\s+)/i,/^(?:[\w,]+\s+)/i,/^(?:[^\n]*)/i,/^(?:scale\s+)/i,/^(?:\d+)/i,/^(?:\s+width\b)/i,/^(?:state\s+)/i,/^(?:.*<<fork>>)/i,/^(?:.*<<join>>)/i,/^(?:.*<<choice>>)/i,/^(?:.*\[\[fork\]\])/i,/^(?:.*\[\[join\]\])/i,/^(?:.*\[\[choice\]\])/i,/^(?:.*direction\s+TB[^\n]*)/i,/^(?:.*direction\s+BT[^\n]*)/i,/^(?:.*direction\s+RL[^\n]*)/i,/^(?:.*direction\s+LR[^\n]*)/i,/^(?:["])/i,/^(?:\s*as\s+)/i,/^(?:[^\n\{]*)/i,/^(?:["])/i,/^(?:[^"]*)/i,/^(?:\w+\s+\w+.*?\{)/i,/^(?:[^\n\s\{]+)/i,/^(?:\n)/i,/^(?:\{)/i,/^(?:\})/i,/^(?:[\n])/i,/^(?:note\s+)/i,/^(?:left of\b)/i,/^(?:right of\b)/i,/^(?:")/i,/^(?:\s*as\s*)/i,/^(?:["])/i,/^(?:[^"]*)/i,/^(?:[^\n]*)/i,/^(?:\s*[^:\n\s\-]+)/i,/^(?:\s*:[^:\n;]+)/i,/^(?:[\s\S]*?\n\s*end note\b)/i,/^(?:stateDiagram\s+)/i,/^(?:stateDiagram-v2\s+)/i,/^(?:hide empty description\b)/i,/^(?:\[\*\])/i,/^(?:[^:\n\s\-\{]+)/i,/^(?:\s*:(?:[^:\n;]|:[^:\n;])+)/i,/^(?:-->)/i,/^(?:--)/i,/^(?::::)/i,/^(?:$)/i,/^(?:.)/i],conditions:{LINE:{rules:[10,11,12],inclusive:!1},struct:{rules:[10,11,12,23,27,30,36,43,44,45,46,56,57,58,72,73,74,75,76,77],inclusive:!1},FLOATING_NOTE_ID:{rules:[65],inclusive:!1},FLOATING_NOTE:{rules:[62,63,64],inclusive:!1},NOTE_TEXT:{rules:[67,68],inclusive:!1},NOTE_ID:{rules:[66],inclusive:!1},NOTE:{rules:[59,60,61],inclusive:!1},STYLEDEF_STYLEOPTS:{rules:[],inclusive:!1},STYLEDEF_STYLES:{rules:[32],inclusive:!1},STYLE_IDS:{rules:[],inclusive:!1},STYLE:{rules:[31],inclusive:!1},CLASS_STYLE:{rules:[29],inclusive:!1},CLASS:{rules:[28],inclusive:!1},CLASSDEFID:{rules:[26],inclusive:!1},CLASSDEF:{rules:[24,25],inclusive:!1},acc_descr_multiline:{rules:[21,22],inclusive:!1},acc_descr:{rules:[19],inclusive:!1},acc_title:{rules:[17],inclusive:!1},SCALE:{rules:[14,15,34,35],inclusive:!1},ALIAS:{rules:[],inclusive:!1},STATE_ID:{rules:[49],inclusive:!1},STATE_STRING:{rules:[50,51],inclusive:!1},FORK_STATE:{rules:[],inclusive:!1},STATE:{rules:[10,11,12,37,38,39,40,41,42,47,48,52,53,54,55],inclusive:!1},ID:{rules:[10,11,12],inclusive:!1},INITIAL:{rules:[0,1,2,3,4,5,6,7,8,9,11,12,13,16,18,20,23,27,30,33,36,55,58,69,70,71,72,73,74,75,77,78,79],inclusive:!0}}};return M})();kt.lexer=ke;function dt(){this.yy={}}return T(dt,"Parser"),u(dt,"Parser"),dt.prototype=kt,kt.Parser=dt,new dt})();xt.parser=xt;var ve=xt,De="TB",ne="TB",te="dir",Q="state",q="root",Lt="relation",Ce="classDef",Ae="style",xe="applyClass",rt="default",oe="divider",le="fill:none",ce="fill: #333",he="c",de="markdown",ue="normal",Ct="rect",At="rectWithTitle",Le="stateStart",we="stateEnd",wt="divider",ee="roundedWithTitle",Ie="note",Ne="noteGroup",it="statediagram",Oe="state",Re=`${it}-${Oe}`,fe="transition",$e="note",Fe="note-edge",Pe=`${fe} ${Fe}`,Be=`${it}-${$e}`,Ge="cluster",Ye=`${it}-${Ge}`,Ve="cluster-alt",Me=`${it}-${Ve}`,pe="parent",Se="note",We="state",It="----",je=`${It}${Se}`,se=`${It}${pe}`,mt=new Map,j=0,ge=0,tt=new Map,Ue=u((t,e,n,s)=>{if(t===wt&&n?.id!==void 0&&tt.has(n.id)){let p=tt.get(n.id);return tt.set(e,p),p}let c=ge++,h=s?void 0:c;return tt.set(e,h),h},"colorSlotFor");function bt(t="",e=0,n="",s=It){let c=n!==null&&n.length>0?`${s}${n}`:"";return`${We}-${t}${c}-${e}`}T(bt,"stateDomId");u(bt,"stateDomId");var He=u((t,e,n,s,c,h,p,S)=>{E.trace("items",e),e.forEach(a=>{switch(a.stmt){case Q:st(t,a,n,s,c,h,p,S);break;case rt:st(t,a,n,s,c,h,p,S);break;case Lt:{st(t,a.state1,n,s,c,h,p,S),st(t,a.state2,n,s,c,h,p,S);let m=p==="neo",b={id:"edge"+j,start:a.state1.id,end:a.state2.id,arrowhead:"normal",arrowTypeEnd:m?"arrow_barb_neo":"arrow_barb",style:le,labelStyle:"",label:W.sanitizeText(a.description??"",F()),arrowheadStyle:ce,labelpos:he,labelType:de,thickness:ue,classes:fe,look:p};c.push(b),j++}break}})},"setupDoc"),re=u((t,e=ne)=>{let n=e;if(t.doc)for(let s of t.doc)s.stmt==="dir"&&(n=s.value);return n},"getDir");function et(t,e,n){if(!e.id||e.id==="</join></fork>"||e.id==="</choice>")return;e.cssClasses&&(Array.isArray(e.cssCompiledStyles)||(e.cssCompiledStyles=[]),e.cssClasses.split(" ").forEach(c=>{let h=n.get(c);h&&(e.cssCompiledStyles=[...e.cssCompiledStyles??[],...h.styles])}));let s=t.find(c=>c.id===e.id);s?Object.assign(s,e):t.push(e)}T(et,"insertOrUpdateNode");u(et,"insertOrUpdateNode");function ye(t){return t?.classes?.join(" ")??""}T(ye,"getClassesFromDbInfo");u(ye,"getClassesFromDbInfo");function me(t){return t?.styles??[]}T(me,"getStylesFromDbInfo");u(me,"getStylesFromDbInfo");var st=u((t,e,n,s,c,h,p,S)=>{let a=e.id,m=n.get(a),b=ye(m),L=me(m),w=F(),R=b.trim()!==""||L.length>0;if(E.info("dataFetcher parsedItem",e,m,L),a!=="root"){let D=Ct;e.start===!0?D=Le:e.start===!1&&(D=we),e.type!==rt&&(D=e.type),mt.get(a)||mt.set(a,{id:a,shape:D,description:W.sanitizeText(a,w),cssClasses:`${b} ${Re}`,cssStyles:L});let f=mt.get(a);e.description&&(Array.isArray(f.description)?(f.shape=At,f.description.push(e.description)):f.description?.length&&f.description.length>0?(f.shape=At,f.description===a?f.description=[e.description]:f.description=[f.description,e.description]):(f.shape=Ct,f.description=e.description),f.description=W.sanitizeTextOrArray(f.description,w)),f.description?.length===1&&f.shape===At&&(f.type==="group"?f.shape=ee:f.shape=Ct),!f.type&&e.doc&&(E.info("Setting cluster for XCX",a,re(e)),f.type="group",f.isGroup=!0,f.dir=re(e),f.shape=e.type===oe?wt:ee,f.colorIndex=Ue(f.shape,a,t,R),f.cssClasses=`${f.cssClasses} ${Ye} ${h?Me:""}`);let I={labelStyle:"",shape:f.shape,label:f.description,cssClasses:f.cssClasses,cssCompiledStyles:[],cssStyles:f.cssStyles,id:a,dir:f.dir,domId:bt(a,j),type:f.type,isGroup:f.type==="group",colorIndex:f.colorIndex,padding:8,rx:10,ry:10,look:p,labelType:"markdown"};if(I.shape===wt&&(I.label=""),t&&t.id!=="root"&&(E.trace("Setting node ",a," to be child of its parent ",t.id),I.parentId=t.id),I.centerLabel=!0,e.note){let C={labelStyle:"",shape:Ie,label:e.note.text,labelType:"markdown",cssClasses:Be,cssStyles:[],cssCompiledStyles:[],id:a+je+"-"+j,domId:bt(a,j,Se),type:"node",isGroup:!1,padding:w.flowchart?.padding,look:p,position:e.note.position},P=a+se,N={labelStyle:"",shape:Ne,label:e.note.text,cssClasses:f.cssClasses,cssStyles:[],id:a+se,domId:bt(a,j,pe),type:"group",isGroup:!0,padding:16,look:p,position:e.note.position};j++,N.id=P,C.parentId=P,et(s,N,S),et(s,C,S),et(s,I,S);let B=a,v=C.id;e.note.position==="left of"&&(B=C.id,v=a),c.push({id:B+"-"+v,start:B,end:v,arrowhead:"none",arrowTypeEnd:"",style:le,labelStyle:"",classes:Pe,pattern:"dashed",arrowheadStyle:ce,labelpos:he,labelType:de,thickness:ue,look:p})}else et(s,I,S)}e.doc&&(E.trace("Adding nodes children "),He(e,e.doc,n,s,c,!h,p,S))},"dataFetcher"),ze=u(()=>{mt.clear(),j=0,ge=0,tt.clear()},"reset"),be=u((t,e=ne)=>{if(!t.doc)return e;let n=e;for(let s of t.doc)s.stmt==="dir"&&(n=s.value);return n},"getDir"),Ke=u(function(t,e){return e.db.getClasses()},"getClasses"),Xe=u(async function(t,e,n,s){E.info("REF0:"),E.info("Drawing state diagram (v2)",e);let{securityLevel:c,state:h,layout:p}=F();s.db.extract(s.db.getRootDocV2());let S=s.db.getData(),a=Qt(e,c);S.type=s.type,S.layoutAlgorithm=Jt(p),S.nodeSpacing=h?.nodeSpacing||50,S.rankSpacing=h?.rankSpacing||50,F().look==="neo"?S.markers=["barbNeo"]:S.markers=["barb"],S.diagramId=e,await Xt(S,a);let b=8;try{(typeof s.db.getLinks=="function"?s.db.getLinks():new Map).forEach((w,R)=>{let D=typeof R=="string"?R:typeof R?.id=="string"?R.id:"",f=S.nodes.find(v=>v.id===D);if(!D){E.warn("\u26A0\uFE0F Invalid or missing stateId from key:",JSON.stringify(R));return}let I=a.node()?.querySelectorAll("g.node, g.rough-node"),C;if(I?.forEach(v=>{let z=v.textContent?.trim();(v.id===f?.domId||z===D)&&(C=v)}),!C){E.warn("\u26A0\uFE0F Could not find node matching text:",D);return}let P=C.parentNode;if(!P){E.warn("\u26A0\uFE0F Node has no parent, cannot wrap:",D);return}let N=document.createElementNS("http://www.w3.org/2000/svg","a"),B=w.url.replace(/^"+|"+$/g,"");if(N.setAttributeNS("http://www.w3.org/1999/xlink","xlink:href",B),N.setAttribute("target","_blank"),w.tooltip){let v=w.tooltip.replace(/^"+|"+$/g,"");N.setAttribute("title",v),C.setAttribute("title",v)}P.replaceChild(N,C),N.appendChild(C),E.info("\u{1F517} Wrapped node in <a> tag for:",D,w.url)})}catch(L){E.error("\u274C Error injecting clickable links:",L)}jt.insertTitle(a,"statediagramTitleText",h?.titleTopMargin??25,s.db.getDiagramTitle()),Zt(a,b,it,h?.useMaxWidth??!0)},"draw"),Je={getClasses:Ke,draw:Xe,getDir:be},x={START_NODE:"[*]",START_TYPE:"start",END_NODE:"[*]",END_TYPE:"end",COLOR_KEYWORD:"color",FILL_KEYWORD:"fill",BG_FILL:"bgFill",STYLECLASS_SEP:","},ie=u(()=>new Map,"newClassesList"),ae=u(()=>({relations:[],states:new Map,documents:{}}),"newDoc"),yt=u(t=>JSON.parse(JSON.stringify(t)),"clone"),qe=class{static{T(this,"StateDB")}constructor(t){this.version=t,this.nodes=[],this.edges=[],this.rootDoc=[],this.classes=ie(),this.documents={root:ae()},this.currentDocument=this.documents.root,this.startEndCount=0,this.dividerCnt=0,this.links=new Map,this.funs=[],this.getAccTitle=Bt,this.setAccTitle=Pt,this.getAccDescription=Yt,this.setAccDescription=Gt,this.setDiagramTitle=Vt,this.getDiagramTitle=Mt,this.clear(),this.setRootDoc=this.setRootDoc.bind(this),this.getDividerId=this.getDividerId.bind(this),this.setDirection=this.setDirection.bind(this),this.trimColon=this.trimColon.bind(this),this.bindFunctions=this.bindFunctions.bind(this)}static{u(this,"StateDB")}static{this.relationType={AGGREGATION:0,EXTENSION:1,COMPOSITION:2,DEPENDENCY:3}}extract(t){this.clear(!0);for(let s of Array.isArray(t)?t:t.doc)switch(s.stmt){case Q:this.addState(s.id.trim(),s.type,s.doc,s.description,s.note);break;case Lt:this.addRelation(s.state1,s.state2,s.description);break;case Ce:this.addStyleClass(s.id.trim(),s.classes);break;case Ae:this.handleStyleDef(s);break;case xe:this.setCssClass(s.id.trim(),s.styleClass);break;case"click":this.addLink(s.id,s.url,s.tooltip);break}let e=this.getStates(),n=F();ze(),st(void 0,this.getRootDocV2(),e,this.nodes,this.edges,!0,n.look,this.classes);for(let s of this.nodes)if(Array.isArray(s.label)){if(s.description=s.label.slice(1),s.isGroup&&s.description.length>0)throw new Error(`Group nodes can only have label. Remove the additional description for node [${s.id}]`);s.label=s.label[0]}}handleStyleDef(t){let e=t.id.trim().split(","),n=t.styleClass.split(",");for(let s of e){let c=this.getState(s);if(!c){let h=s.trim();this.addState(h),c=this.getState(h)}c&&(c.styles=n.map(h=>h.replace(/;/g,"")?.trim()))}}setRootDoc(t){E.info("Setting root doc",t),this.rootDoc=t,this.version===1?this.extract(t):this.extract(this.getRootDocV2())}docTranslator(t,e,n){if(e.stmt===Lt){this.docTranslator(t,e.state1,!0),this.docTranslator(t,e.state2,!1);return}if(e.stmt===Q&&(e.id===x.START_NODE?(e.id=t.id+(n?"_start":"_end"),e.start=n):e.id=e.id.trim()),e.stmt!==q&&e.stmt!==Q||!e.doc)return;let s=[],c=[];for(let h of e.doc)if(h.type===oe){let p=yt(h);p.doc=yt(c),s.push(p),c=[]}else c.push(h);if(s.length>0&&c.length>0){let h={stmt:Q,id:Wt(),type:"divider",doc:yt(c)};s.push(yt(h)),e.doc=s}e.doc.forEach(h=>this.docTranslator(e,h,!0))}getRootDocV2(){return this.docTranslator({id:q,stmt:q},{id:q,stmt:q,doc:this.rootDoc},!0),{id:q,doc:this.rootDoc}}addState(t,e=rt,n=void 0,s=void 0,c=void 0,h=void 0,p=void 0,S=void 0){let a=t?.trim();if(!this.currentDocument.states.has(a))E.info("Adding state ",a,s),this.currentDocument.states.set(a,{stmt:Q,id:a,descriptions:[],type:e,doc:n,note:c,classes:[],styles:[],textStyles:[]});else{let m=this.currentDocument.states.get(a);if(!m)throw new Error(`State not found: ${a}`);m.doc||(m.doc=n),m.type||(m.type=e)}if(s&&(E.info("Setting state description",a,s),(Array.isArray(s)?s:[s]).forEach(b=>this.addDescription(a,b.trim()))),c){let m=this.currentDocument.states.get(a);if(!m)throw new Error(`State not found: ${a}`);m.note=c,m.note.text=W.sanitizeText(m.note.text,F())}h&&(E.info("Setting state classes",a,h),(Array.isArray(h)?h:[h]).forEach(b=>this.setCssClass(a,b.trim()))),p&&(E.info("Setting state styles",a,p),(Array.isArray(p)?p:[p]).forEach(b=>this.setStyle(a,b.trim()))),S&&(E.info("Setting state styles",a,p),(Array.isArray(S)?S:[S]).forEach(b=>this.setTextStyle(a,b.trim())))}clear(t){this.nodes=[],this.edges=[],this.funs=[this.setupToolTips.bind(this)],this.documents={root:ae()},this.currentDocument=this.documents.root,this.startEndCount=0,this.classes=ie(),t||(this.links=new Map,Ft())}getState(t){return this.currentDocument.states.get(t)}getStates(){return this.currentDocument.states}logDocuments(){E.info("Documents = ",this.documents)}getRelations(){return this.currentDocument.relations}addLink(t,e,n){this.links.set(t,{url:e,tooltip:n}),E.warn("Adding link",t,e,n)}getLinks(){return this.links}startIdIfNeeded(t=""){return t===x.START_NODE?(this.startEndCount++,`${x.START_TYPE}${this.startEndCount}`):t}startTypeIfNeeded(t="",e=rt){return t===x.START_NODE?x.START_TYPE:e}endIdIfNeeded(t=""){return t===x.END_NODE?(this.startEndCount++,`${x.END_TYPE}${this.startEndCount}`):t}endTypeIfNeeded(t="",e=rt){return t===x.END_NODE?x.END_TYPE:e}addRelationObjs(t,e,n=""){let s=this.startIdIfNeeded(t.id.trim()),c=this.startTypeIfNeeded(t.id.trim(),t.type),h=this.startIdIfNeeded(e.id.trim()),p=this.startTypeIfNeeded(e.id.trim(),e.type);this.addState(s,c,t.doc,t.description,t.note,t.classes,t.styles,t.textStyles),this.addState(h,p,e.doc,e.description,e.note,e.classes,e.styles,e.textStyles),this.currentDocument.relations.push({id1:s,id2:h,relationTitle:W.sanitizeText(n,F())})}addRelation(t,e,n){if(typeof t=="object"&&typeof e=="object")this.addRelationObjs(t,e,n);else if(typeof t=="string"&&typeof e=="string"){let s=this.startIdIfNeeded(t.trim()),c=this.startTypeIfNeeded(t),h=this.endIdIfNeeded(e.trim()),p=this.endTypeIfNeeded(e);this.addState(s,c),this.addState(h,p),this.currentDocument.relations.push({id1:s,id2:h,relationTitle:n?W.sanitizeText(n,F()):void 0})}}addDescription(t,e){let n=this.currentDocument.states.get(t),s=e.startsWith(":")?e.replace(":","").trim():e;n?.descriptions?.push(W.sanitizeText(s,F()))}cleanupLabel(t){return t.startsWith(":")?t.slice(2).trim():t.trim()}getDividerId(){return this.dividerCnt++,`divider-id-${this.dividerCnt}`}addStyleClass(t,e=""){this.classes.has(t)||this.classes.set(t,{id:t,styles:[],textStyles:[]});let n=this.classes.get(t);e&&n&&e.split(x.STYLECLASS_SEP).forEach(s=>{let c=s.replace(/([^;]*);/,"$1").trim();if(RegExp(x.COLOR_KEYWORD).exec(s)){let p=c.replace(x.FILL_KEYWORD,x.BG_FILL).replace(x.COLOR_KEYWORD,x.FILL_KEYWORD);n.textStyles.push(p)}n.styles.push(c)})}getClasses(){return this.classes}setupToolTips(t){let e=qt();gt(t).select("svg").selectAll("g.node, g.rough-node").on("mouseover",c=>{let h=gt(c.currentTarget),p=h.attr("title");if(p===null)return;let S=c.currentTarget?.getBoundingClientRect();e.transition().duration(200).style("opacity",".9"),e.style("left",window.scrollX+S.left+(S.right-S.left)/2+"px").style("top",window.scrollY+S.bottom+"px"),e.html($t.sanitize(p)),h.classed("hover",!0)}).on("mouseout",c=>{e.transition().duration(500).style("opacity",0),gt(c.currentTarget).classed("hover",!1)})}setCssClass(t,e){t.split(",").forEach(n=>{let s=this.getState(n);if(!s){let c=n.trim();this.addState(c),s=this.getState(c)}s?.classes?.push(e)})}setStyle(t,e){this.getState(t)?.styles?.push(e)}setTextStyle(t,e){this.getState(t)?.textStyles?.push(e)}bindFunctions(t){this.funs.forEach(e=>{e(t)})}getDirectionStatement(){return this.rootDoc.find(t=>t.stmt===te)}getDirection(){return this.getDirectionStatement()?.value??De}setDirection(t){let e=this.getDirectionStatement();e?e.value=t:this.rootDoc.unshift({stmt:te,value:t})}trimColon(t){return t.startsWith(":")?t.slice(1).trim():t.trim()}getData(){let t=F();for(let e of this.nodes)e.wrappingWidth??=t.state?.wrappingWidth,e.isGroup||(e.minWidth??=t.state?.minNodeWidth);return{nodes:this.nodes,edges:this.edges,other:{},config:t,direction:be(this.getRootDocV2())}}getConfig(){return F().state}},Qe=u(t=>{let{theme:e,bkgColorArray:n,borderColorArray:s}=t;if(!Ht(e,s))return"";let c=zt(t.look),h=Ut(n),p="";for(let S=0;S<Kt(s);S++){let a=s[S],m=h?`fill: ${n[S%n.length]};`:"",b=`[data-look="${c}"][data-color-id="color-${S}"]`;p+=`

    /* The title strip: \`rect.outer\` spans the whole composite and \`rect.inner\` covers
       the body, so what stays visible of \`outer\` is the band behind the label. */
    ${b}.statediagram-cluster rect.outer {
      stroke: ${a};
      ${m}
    }

    ${b}.statediagram-cluster rect.inner {
      stroke: ${a};
    }

    /* Concurrency regions. Siblings of one composite share a slot, so a divided composite
       reads as one thing split into parts rather than as several composites. */
    ${b}.statediagram-cluster rect.divider {
      stroke: ${a};
      ${m}
    }

    /* handDrawn draws the same container as roughjs shapes rather than plain rects, so it
       needs its own rules. \`roundedWithTitle\` and \`divider\` name those groups \`outer\`,
       \`inner\` and \`divider\` to match the classic branch, which is what lets these
       discriminate -- a bare \`.statediagram-cluster path\` rule reached the body as well and
       tinted the whole composite, losing \`compositeBackground\` and diverging from what
       classic and neo do.

       roughjs emits two paths per shape and marks them: the filled shape carries
       \`stroke="none"\` and the sketched outline carries \`fill="none"\`. Splitting on that is
       what keeps \`fill\` off the outline -- a rough outline is open squiggles, not a closed
       region, so filling it produces smears -- and keeps \`stroke\` off the fill shape, which
       would otherwise gain an edge it was drawn without. */
    ${b}.statediagram-cluster .outer path[stroke='none'] {
      ${m}
    }

    ${b}.statediagram-cluster .outer path[fill='none'] {
      stroke: ${a};
    }

    /* No \`.inner\` rule on purpose. The body shape is left entirely alone under handDrawn,
       where a rect's \`inner\` counterpart cannot be recoloured safely: roughjs draws a
       hachure fill as *stroked* lines, so its fill paths carry \`fill="none"\` exactly like
       the outline and no selector separates them. An \`.inner\` stroke rule therefore
       repainted the hatching of every alt composite in the palette colour instead of
       leaving it on \`altBackground\`. The container still reads as palette-coloured: the
       \`outer\` shape spans the whole composite, so its outline already frames the body. */

    /* Regions split the same way, which is why \`divider\` fills solid rather than taking
       roughjs's default hachure -- see the note on that call. Hatched, both of its paths
       carried \`fill="none"\` and these two rules degenerated: the tint matched nothing and
       the border rule repainted the hatching. */
    ${b}.statediagram-cluster .divider path[stroke='none'] {
      ${m}
    }

    ${b}.statediagram-cluster .divider path[fill='none'] {
      stroke: ${a};
    }
    `}return p},"genColor"),Ze=u(t=>`
${Qe(t)}
defs [id$="-barbEnd"] {
    fill: ${t.transitionColor};
    stroke: ${t.transitionColor};
  }
g.stateGroup text {
  fill: ${t.nodeBorder};
  stroke: none;
  font-size: 10px;
}
g.stateGroup text {
  fill: ${t.textColor};
  stroke: none;
  font-size: 10px;

}
g.stateGroup .state-title {
  font-weight: bolder;
  fill: ${t.stateLabelColor};
}

g.stateGroup rect {
  fill: ${t.mainBkg};
  stroke: ${t.nodeBorder};
}

g.stateGroup line {
  stroke: ${t.lineColor};
  stroke-width: ${t.strokeWidth||1};
}

.transition {
  stroke: ${t.transitionColor};
  stroke-width: ${t.strokeWidth||1};
  fill: none;
}

.stateGroup .composit {
  fill: ${t.background};
  border-bottom: 1px
}

.stateGroup .alt-composit {
  fill: #e0e0e0;
  border-bottom: 1px
}

.state-note {
  stroke: ${t.noteBorderColor};
  fill: ${t.noteBkgColor};

  text {
    fill: ${t.noteTextColor};
    stroke: none;
    font-size: 10px;
  }
}

.stateLabel .box {
  stroke: none;
  stroke-width: 0;
  fill: ${t.mainBkg};
  opacity: 0.5;
}

.edgeLabel .label rect {
  fill: ${t.labelBackgroundColor};
  opacity: 0.5;
}
.edgeLabel {
  background-color: ${t.edgeLabelBackground};
  p {
    background-color: ${t.edgeLabelBackground};
  }
  rect {
    opacity: 0.5;
    background-color: ${t.edgeLabelBackground};
    fill: ${t.edgeLabelBackground};
  }
  text-align: center;
}
.edgeLabel .label text {
  fill: ${t.transitionLabelColor||t.tertiaryTextColor};
}
.label div .edgeLabel {
  color: ${t.transitionLabelColor||t.tertiaryTextColor};
}

.stateLabel text {
  fill: ${t.stateLabelColor};
  font-size: 10px;
  font-weight: bold;
}

.node circle.state-start {
  fill: ${t.specialStateColor};
  stroke: ${t.specialStateColor};
}

.node .fork-join {
  fill: ${t.specialStateColor};
  stroke: ${t.specialStateColor};
}

.node circle.state-end {
  fill: ${t.innerEndBackground};
  stroke: ${t.background};
  stroke-width: 1.5
}
.end-state-inner {
  fill: ${t.compositeBackground||t.background};
  // stroke: ${t.background};
  stroke-width: 1.5
}

.node rect {
  fill: ${t.stateBkg||t.mainBkg};
  stroke: ${t.stateBorder||t.nodeBorder};
  stroke-width: ${t.strokeWidth||1}px;
}
.node polygon {
  fill: ${t.mainBkg};
  stroke: ${t.stateBorder||t.nodeBorder};;
  stroke-width: ${t.strokeWidth||1}px;
}
[id$="-barbEnd"] {
  fill: ${t.lineColor};
}

.statediagram-cluster rect {
  fill: ${t.compositeTitleBackground};
  stroke: ${t.stateBorder||t.nodeBorder};
  stroke-width: ${t.strokeWidth||1}px;
}

.cluster-label, .nodeLabel {
  color: ${t.stateLabelColor};
  // line-height: 1;
}

.statediagram-cluster rect.outer {
  rx: 5px;
  ry: 5px;
}
.statediagram-state .divider {
  stroke: ${t.stateBorder||t.nodeBorder};
}

.statediagram-state .title-state {
  rx: 5px;
  ry: 5px;
}
.statediagram-cluster.statediagram-cluster .inner {
  fill: ${t.compositeBackground||t.background};
}
.statediagram-cluster.statediagram-cluster-alt .inner {
  fill: ${t.altBackground?t.altBackground:"#efefef"};
}

.statediagram-cluster .inner {
  rx:0;
  ry:0;
}

.statediagram-state rect.basic {
  rx: 5px;
  ry: 5px;
}
.statediagram-state rect.divider {
  stroke-dasharray: 10,10;
  fill: ${t.altBackground?t.altBackground:"#efefef"};
}

.note-edge {
  stroke-dasharray: 5;
}

.statediagram-note rect {
  fill: ${t.noteBkgColor};
  stroke: ${t.noteBorderColor};
  stroke-width: 1px;
  rx: 0;
  ry: 0;
}
.statediagram-note rect {
  fill: ${t.noteBkgColor};
  stroke: ${t.noteBorderColor};
  stroke-width: 1px;
  rx: 0;
  ry: 0;
}

.statediagram-note text {
  fill: ${t.noteTextColor};
}

.statediagram-note .nodeLabel {
  color: ${t.noteTextColor};
}
.statediagram .edgeLabel {
  color: red; // ${t.noteTextColor};
}

[id$="-dependencyStart"], [id$="-dependencyEnd"] {
  fill: ${t.lineColor};
  stroke: ${t.lineColor};
  stroke-width: ${t.strokeWidth||1};
}

.statediagramTitleText {
  text-anchor: middle;
  font-size: 18px;
  fill: ${t.textColor};
}

[data-look="neo"].statediagram-cluster rect {
  fill: ${t.mainBkg};
  stroke: ${t.useGradient?"url("+t.svgId+"-gradient)":t.stateBorder||t.nodeBorder};
  stroke-width: ${t.strokeWidth??1};
}
[data-look="neo"].statediagram-cluster rect.outer {
  rx: ${t.radius}px;
  ry: ${t.radius}px;
  filter: ${t.dropShadow?t.dropShadow.replace("url(#drop-shadow)",`url(${t.svgId}-drop-shadow)`):"none"}
}
`,"getStyles"),ts=Ze,Ts={parser:ve,get db(){return new qe(2)},renderer:Je,styles:ts,init:u(t=>{t.state||(t.state={}),t.state.arrowMarkerAbsolute=t.arrowMarkerAbsolute},"init")};export{Ts as diagram};
//# sourceMappingURL=stateDiagram-v2-A6IESKWZ-CERVKO2V.js.map
