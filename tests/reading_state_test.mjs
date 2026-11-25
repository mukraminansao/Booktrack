// Meaningful race/failure checks for account progress, using only Node built-ins.
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
const source=await readFile(new URL('../assets/js/reading-state.js',import.meta.url),'utf8');
const {readingState}=await import('data:text/javascript;base64,'+Buffer.from(source).toString('base64'));
let passed=0;
const check=(name,fn)=>{fn();passed++;console.log('PASS '+name);};
const deferred=()=>{let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});return{promise,resolve,reject};};
const response=(body={ok:true},status=200)=>({ok:status<400,status,json:async()=>body});
function environment(fetcher){
  const events={},timers=new Map(),messages=[],beacons=[];let id=0;
  globalThis.setTimeout=(fn,delay)=>{timers.set(++id,{fn,delay});return id;};
  globalThis.clearTimeout=key=>timers.delete(key);
  globalThis.window={addEventListener:(name,fn)=>{events[name]=fn;}};
  globalThis.document={querySelector:()=>({content:'test-csrf'}),addEventListener:()=>{},createElement:()=>({setAttribute(){},textContent:'',hidden:false})};
  Object.defineProperty(globalThis,'navigator',{configurable:true,value:{sendBeacon:(url,body)=>{beacons.push({url,body});return true;}}});
  globalThis.fetch=fetcher;
  const root={dataset:{bookId:'7',api:'/api/reading.php',revision:'file-v1'},prepend:node=>{root.warning=node;}};
  const state=readingState(root,'ebook',(...args)=>messages.push(args));
  return{state,events,timers,messages,beacons,root};
}
{
  const first=deferred(),second=deferred(),sent=[];
  const env=environment(async(url,options)=>{sent.push(Number(options.body.get('position')));return sent.length===1?first.promise:second.promise;});
  const saving=env.state.save({position:1,total:3},true);
  env.state.save({position:3,total:3},true);first.reject(Error('Offline'));
  for(let i=0;i<8&&sent.length<2;i++)await Promise.resolve();
  second.resolve(response());await saving;await env.state.flush();
  check('a newer page replaces a failed in-flight page without replaying stale state',()=>assert.deepEqual(sent,[1,3]));
  check('connection recovery clears the save error',()=>assert.equal(env.messages.at(-1)[0],'Your reading position is saved.'));
}
{
  let online=false;const sent=[];
  const env=environment(async(url,options)=>{sent.push(Number(options.body.get('position')));if(!online)throw Error('Offline');return response();});
  const saved=await env.state.save({position:1,total:3},true);check('offline save reports failure',()=>assert.equal(saved,false));
  env.state.save({position:2,total:3});env.state.save({position:3,total:3});online=true;await env.events.online();
  check('reconnection saves only the most recent queued position',()=>assert.deepEqual(sent,[1,3]));
  check('successful retry leaves no old save pending',()=>assert.equal([...env.timers.values()].filter(t=>t.delay===10000).length,0));
}
{
  const env=environment(async()=>response());env.state.save({position:1,total:3});env.state.save({position:2,total:3});env.events.pagehide();
  check('leaving the page sends the latest position with CSRF',()=>{assert.equal(env.beacons[0].body.get('position'),'2');assert.equal(env.beacons[0].body.get('_token'),'test-csrf');});
}
{
  const env=environment(async()=>({ok:false,json:async()=>{throw Error('Unexpected token <');}}));
  let message='';try{await env.state.load();}catch(error){message=error.message;}
  check('HTML error responses become readable messages',()=>assert.match(message,/did not return a valid response/));
}
{
  const env=environment(async(url,options)=>new Promise((resolve,reject)=>options.signal.addEventListener('abort',()=>reject(new DOMException('Timeout','AbortError')))));
  const saving=env.state.save({position:2,total:3},true);
  [...env.timers.values()].find(t=>t.delay===15000).fn();
  assert.equal(await saving,false);
  check('stalled saves time out visibly and remain queued for retry',()=>{assert.match(env.messages.at(-1)[0],/too long/);assert.ok([...env.timers.values()].some(t=>t.delay===10000));});
}
for(const code of [401,419,409]){
  let calls=0;const env=environment(async()=>{calls++;return response({ok:false,error:'Refresh required.'},code);});
  await env.state.save({position:2,total:3},true);await env.events.online();await env.state.save({position:3,total:3},true);
  check('HTTP '+code+' ends retries and keeps an independent warning',()=>{assert.equal(calls,1);assert.equal(env.root.dataset.saveBlocked,'1');assert.equal(env.root.warning.hidden,false);assert.ok(![...env.timers.values()].some(t=>t.delay===10000));});
}
{
 const env=environment(async()=>response({ok:true,revision:'file-v2'}));await assert.rejects(env.state.load(),/file changed/);
 check('a stale file cannot load state from its replacement',()=>assert.equal(env.root.dataset.saveBlocked,'1'));
}
{
 const tokens=[];let calls=0;const env=environment(async(url,options)=>{if(!options.body)return response({ok:true,revision:'file-v1',progress_version:'v1'});tokens.push(options.body.get('progress_version'));return response({ok:true,progress_version:'v'+(++calls+1)});});
 await env.state.load();await env.state.save({position:1,total:3},true);await env.state.save({position:2,total:3},true);
 check('each save carries the last acknowledged progress version',()=>assert.deepEqual(tokens,['v1','v2']));
}
{
 const env=environment(async()=>{throw Error('Offline');});await env.state.save({position:2,total:3},true);let warned=false;
 env.events.beforeunload({preventDefault(){warned=true;}});
 check('unsaved reading positions warn before leaving',()=>assert.equal(warned,true));
 check('unrelated reader status cannot clear the save failure banner',()=>{env.messages.push(['Page 3 of 3']);assert.equal(env.root.warning.hidden,false);assert.match(env.root.warning.textContent,/Not saved/);});
}
console.log(`${passed} passed, 0 failed`);
