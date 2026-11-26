const {test,expect}=require('@playwright/test');
const fs=require('node:fs/promises');
const path=require('node:path');
const {execFileSync}=require('node:child_process');
const routes=[['dashboard','/discover.php'],['catalogue','/discover.php?view=all'],['book','/book.php?id=7'],['loans','/loans.php'],['favorites','/favorites.php'],['profile','/profile.php'],['messages','/messages.php']];
const screenshotStyle='.nav__icon{background:transparent!important;box-shadow:none!important;color:var(--quiet)!important}.nav a{color:var(--quiet)!important}.nav a::after{display:none!important}.nav__icon .icon{stroke-width:1.3!important}.topbar__btn .count{visibility:hidden!important}.tabbar a{color:var(--quiet)!important;font-weight:500!important}.tabbar__icon{background:transparent!important;box-shadow:none!important;color:var(--quiet)!important}.tabbar .icon{stroke-width:1.4!important}';
async function signIn(page,context,theme){
  await context.addCookies([{name:'bt_theme',value:theme,url:test.info().project.use.baseURL||'http://127.0.0.1:8115'}]);
  await page.goto('/login.php');await page.getByLabel('Username',{exact:true}).fill('alice');await page.getByLabel('Password',{exact:true}).fill('member123');
  await page.getByRole('button',{name:'Sign in',exact:true}).click();await expect(page).toHaveURL(/discover\.php$/);
}
async function shell(page){
  await page.evaluate(()=>document.fonts.ready);
  return page.evaluate(()=>{
    const style=s=>getComputedStyle(document.querySelector(s));const box=s=>{const r=document.querySelector(s).getBoundingClientRect();return [r.x,r.width].map(n=>Math.round(n*100)/100);};
    const body=getComputedStyle(document.body),rail=style('.sidebar'),header=style('.topbar');
    return {app:box('.app'),sidebar:box('.sidebar'),railDisplay:rail.display,railMargin:rail.margin,headerHeight:header.height,headerPadding:header.padding,iconSize:style('.nav__icon .icon').width,iconStroke:style('.nav a:not([aria-current]) .nav__icon .icon').strokeWidth,navGap:style('.nav').gap,searchSize:style('.reading-search input').fontSize,avatarSize:style('.reading-profile .avatar').width,palette:['--paper','--paper-light','--ink','--quiet','--gold-text','--green','--red'].map(v=>body.getPropertyValue(v).trim())};
  });
}
async function compareNavigation(reference,current,name,testInfo){
  const first=testInfo.outputPath('navigation-reference.png'),second=testInfo.outputPath('navigation-'+name+'.png');
  await fs.writeFile(first,reference);await fs.writeFile(second,current);
  const difference=JSON.parse(execFileSync('php',[path.join(__dirname,'pixel_compare.php'),first,second],{encoding:'utf8'}));
  if(difference.ratio>.0025){await testInfo.attach('dashboard-navigation.png',{body:reference,contentType:'image/png'});await testInfo.attach('current-navigation.png',{body:current,contentType:'image/png'});}
  expect(difference.ratio,`${name} navigation pixel difference: ${JSON.stringify(difference)}`).toBeLessThanOrEqual(.0025);
}
for(const width of [1440,1280,390])for(const theme of ['light','dark']){
  test(`shared shell ${width}px ${theme}`,async({page,context},testInfo)=>{
    const errors=[];page.on('pageerror',e=>errors.push(e.message));
    await page.setViewportSize({width,height:width<768?844:1000});await signIn(page,context,theme);
    let baseline,headerImage,navImage;
    for(const [name,route] of routes){
      await page.goto(route);await expect(page.locator('html')).toHaveAttribute('data-theme',theme);
      const metrics=await shell(page);baseline??=metrics;expect(metrics,`${name} shell must match dashboard`).toEqual(baseline);
      expect(Number.parseFloat(metrics.headerHeight)).toBe(width===1440?72:width===1280?64:74);
      expect(metrics.sidebar[1]).toBe(width===1440?80:width===1280?68:0);
      expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
      const active=page.locator('.sidebar .nav a[aria-current] .icon');if(await active.count())await expect(active).toHaveCSS('stroke-width','2.1px');
      await expect(page.locator('#main')).toBeVisible();await expect(page.locator('#main h1')).toBeVisible();
      if(name!=='dashboard')await expect(page.getByRole('navigation',{name:'Breadcrumb',exact:true})).toBeVisible();
      const header=await page.locator('.topbar').screenshot({animations:'disabled',caret:'hide',style:screenshotStyle});
      headerImage??=header;expect(header.equals(headerImage),`${name} header pixels must match dashboard`).toBe(true);
      const nav=await page.locator(width<768?'.tabbar':'.sidebar .nav').screenshot({animations:'disabled',style:screenshotStyle});
      navImage??=nav;await compareNavigation(navImage,nav,name,testInfo);
      await page.screenshot({path:testInfo.outputPath(`${name}-${width}-${theme}.png`),fullPage:true,animations:'disabled'});
    }
    expect(errors).toEqual([]);
  });
}
test('preview position and settings continue between book details and dashboard',async({page,context})=>{
  await signIn(page,context,'light');await page.goto('/discover.php?listen=7');
  const position=page.getByRole('slider',{name:'Preview position',exact:true});await expect(position).toBeEnabled();
  await position.focus();await position.press('Home');for(let i=0;i<43;i++)await position.press('ArrowRight');
  await page.getByRole('combobox',{name:'Preview speed',exact:true}).selectOption('1.5');
  await page.goto('/book.php?id=7');await expect(page.getByRole('slider',{name:'Preview position',exact:true})).toHaveValue('43');await expect(page.getByRole('combobox',{name:'Preview speed',exact:true})).toHaveValue('1.5');
  await expect(page.getByRole('button',{name:'Play book preview',exact:true})).toHaveAttribute('aria-pressed','false');
  await page.goto('/book.php?id=14');await expect(page.getByRole('slider',{name:'Preview position',exact:true})).toHaveValue('0');
  await page.goto('/discover.php');await expect(page.locator('.reading-player-title')).toHaveText('Atomic Habits');await expect(page.getByRole('slider',{name:'Preview position',exact:true})).toHaveValue('43');
});
