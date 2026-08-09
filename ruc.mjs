import { chromium } from 'playwright';
const B='http://127.0.0.1:8123', OUT='/tmp/claude-0/-home-user-app-documentation/a506be63-2cd9-5421-9c25-d739aa48b553/scratchpad/rev';
const b=await chromium.launch({executablePath:'/opt/pw-browsers/chromium'});
const p=await b.newPage({viewport:{width:1280,height:900}});
const llamadas=[]; const errs=[];
p.on('console',m=>{if(m.type()==='error')errs.push(m.text().slice(0,160));});
p.on('pageerror',e=>errs.push('PAGEERROR '+e.message.slice(0,160)));
p.on('request',r=>{ if(r.url().includes('lookup_ruc')) llamadas.push(r.url().split('?')[1]); });
await p.goto(`${B}/es/login`,{waitUntil:'networkidle'});await p.waitForTimeout(800);
await p.getByPlaceholder('tu@empresa.com').fill('super@example.com');
await p.locator('input[type="password"]').first().fill('secreto123');
await p.getByRole('button',{name:'Iniciar sesión'}).click();await p.waitForTimeout(2500);
await p.goto(`${B}/es/business_management/companies/create`,{waitUntil:'domcontentloaded'});await p.waitForTimeout(1800);
for(let i=0;i<5;i++){const x=p.locator('.driver-popover-close-btn');if(await x.count()){await x.first().click().catch(()=>{});await p.waitForTimeout(150);}else break;}
// RUC incompleto: no debe llamar
const ruc = p.locator('input').nth(2);
await p.getByPlaceholder(/RUC|20\d/).first().fill('2051').catch(async()=>{ await ruc.fill('2051'); });
await p.waitForTimeout(900);
console.log('tras RUC corto  -> llamadas:', llamadas.length);
// RUC completo: debe llamar una vez (debounce)
await p.getByPlaceholder(/RUC|20\d/).first().fill('20512345678').catch(async()=>{ await ruc.fill('20512345678'); });
await p.waitForTimeout(1500);
console.log('tras RUC de 11  -> llamadas:', llamadas.length, llamadas);
console.log('errores consola:', errs.slice(0,3));
console.log('estado interno:', await p.evaluate(()=>({ruc:document.querySelectorAll('input')[2]?.value, ayuda:[...document.querySelectorAll('.ant-form-item-explain, .ant-form-item-extra')].map(e=>e.innerText).join(' | ')})));
await p.screenshot({path:`${OUT}/f-ruc.png`});
await b.close();
