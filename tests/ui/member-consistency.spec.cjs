const {test,expect}=require('@playwright/test');
const fs=require('node:fs/promises');
const path=require('node:path');
const {execFileSync}=require('node:child_process');
const routes=[['dashboard','/discover.php'],['catalogue','/discover.php?view=all'],['book','/book.php?id=7'],['loans','/loans.php'],['favorites','/favorites.php'],['profile','/profile.php'],['messages','/messages.php']];
const screenshotStyle='.shell,.topbar{background:var(--paper)!important}.nav__icon{background:transparent!important;box-shadow:none!important;color:var(--quiet)!important}.nav a{color:var(--quiet)!important}.nav a::after{display:none!important}.nav__icon .icon{stroke-width:1.3!important}.topbar__btn .count{visibility:hidden!important}.tabbar a{color:var(--quiet)!important;font-weight:500!important}.tabbar__icon{background:transparent!important;box-shadow:none!important;color:var(--quiet)!important}.tabbar .icon{stroke-width:1.4!important}';
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
    return {app:box('.app'),sidebar:box('.sidebar'),railDisplay:rail.display,railMargin:rail.margin,headerHeight:header.height,headerPadding:header.padding,iconSize:style('.nav__icon .icon').width,iconStroke:style('.nav a:not([aria-current]) .nav__icon .icon').strokeWidth,navGap:style('.nav').gap,searchSize:style('.reading-search').fontSize,avatarSize:style('.reading-profile .avatar').width,palette:['--paper','--paper-light','--ink','--quiet','--gold-text','--green','--red'].map(v=>body.getPropertyValue(v).trim())};
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
      const approvedPalette=theme==='dark'?['#24291f','#1a2018','#f1eee2','#c4c5b8','#e0ce8c','#bfd3aa','#f1b3a0']:[width>=1200?'#f1efe3':'#f8f5ed',width>=1200?'#fff':'#fffdf9','#201f1a','#68655e','#826a29','#42633c','#9d3c30'];
      expect(metrics.palette,`${name} keeps the approved theme palette`).toEqual(approvedPalette);
      expect(Number.parseFloat(metrics.headerHeight)).toBe(width===1440?84:width===1280?76:82);
      expect(metrics.sidebar[1]).toBe(width===1440?88:width===1280?80:0);
      const shellBackground=await page.locator('.shell').evaluate(e=>getComputedStyle(e).backgroundImage);
      if(name==='dashboard'&&width>=1200)expect(shellBackground).toContain('linear-gradient');else expect(shellBackground).toBe('none');
      expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
      const active=page.locator('.sidebar .nav a[aria-current] .icon');if(await active.count())await expect(active).toHaveCSS('stroke-width','2.1px');
      await expect(page.locator('#main')).toBeVisible();await expect(page.locator('#main h1')).toBeVisible();
      if(name==='dashboard'){
        expect(await page.locator('.reading-greeting p').evaluate(e=>parseFloat(getComputedStyle(e).fontSize))).toBeGreaterThanOrEqual(16);
        expect((await page.locator('.reading-jacket').first().boundingBox()).width).toBeGreaterThan(110);
        expect((await page.locator('.reading-cta').boundingBox()).height).toBeGreaterThanOrEqual(48);
      }
      if(name==='catalogue')expect(await page.locator('.book-card__title').first().evaluate(e=>parseFloat(getComputedStyle(e).fontSize))).toBeGreaterThanOrEqual(17);
      if(name==='book'){
        expect(await page.locator('.prose').evaluate(e=>parseFloat(getComputedStyle(e).fontSize))).toBeGreaterThanOrEqual(17);
        const action=page.locator('.book-actions .btn').first();expect(await action.evaluate(e=>parseFloat(getComputedStyle(e).fontSize))).toBeGreaterThanOrEqual(16);expect((await action.boundingBox()).height).toBeGreaterThanOrEqual(48);
      }
      if(name!=='dashboard')await expect(page.getByRole('navigation',{name:'Breadcrumb',exact:true})).toBeVisible();
      const header=await page.locator('.topbar').screenshot({animations:'disabled',caret:'hide',style:screenshotStyle});
      headerImage??=header;
      if(!header.equals(headerImage)){await testInfo.attach('dashboard-header.png',{body:headerImage,contentType:'image/png'});await testInfo.attach('current-header.png',{body:header,contentType:'image/png'});}
      expect(header.equals(headerImage),`${name} header pixels must match dashboard`).toBe(true);
      const nav=await page.locator(width<768?'.tabbar':'.sidebar .nav').screenshot({animations:'disabled',style:screenshotStyle});
      navImage??=nav;await compareNavigation(navImage,nav,name,testInfo);
      await page.screenshot({path:testInfo.outputPath(`${name}-${width}-${theme}.png`),fullPage:true,animations:'disabled'});
    }
    // Search stays compact and uses the same accessible flow at every size/theme.
    const trigger=page.locator('[data-palette-open]:visible');await trigger.click();
    const dialog=page.getByRole('dialog',{name:'Search',exact:true});await expect(dialog).toBeVisible();
    const query=dialog.getByRole('combobox',{name:'Book title, author or category',exact:true});
    await expect(query).toBeFocused();await expect(dialog.getByText('A new favourite is a search away.',{exact:true})).toBeVisible();
    const panel=await dialog.boundingBox();expect(panel.height).toBeLessThan(350);expect(panel.width).toBeLessThan(width);
    await expect(query).toHaveCSS('border-top-width','0px');await expect(query).toHaveCSS('font-size','16px');
    await page.screenshot({path:testInfo.outputPath(`search-idle-${width}-${theme}.png`),animations:'disabled'});
    await query.fill('Atomic');await expect(dialog.getByRole('option').filter({hasText:'Atomic Habits'})).toBeVisible();
    await page.screenshot({path:testInfo.outputPath(`search-results-${width}-${theme}.png`),animations:'disabled'});
    await query.fill('zzzz-nothing-matches');await expect(dialog.getByText('Nothing matches “zzzz-nothing-matches”',{exact:true})).toBeVisible();
    await query.fill('');await expect(dialog.getByText('A new favourite is a search away.',{exact:true})).toBeVisible();
    await query.press('Escape');await expect(dialog).not.toBeVisible();await expect(trigger).toBeFocused();
    await page.keyboard.press('/');await expect(dialog).toBeVisible();await query.fill('Atomic');
    await expect(dialog.getByRole('option').filter({hasText:'Atomic Habits'})).toBeVisible();await query.press('ArrowDown');await query.press('Enter');
    await expect(page).toHaveURL(/book\.php\?id=7&return_to=/);
    expect(new URL(page.url()).searchParams.get('return_to')).toBe('/messages.php');
    await page.locator('[data-navigation-back]').click();await expect(page).toHaveURL(/\/messages\.php$/);
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

test('small phone search stays usable when live results fail',async({page,context})=>{
  await page.setViewportSize({width:320,height:640});await signIn(page,context,'light');
  await page.route('**/api/search.php?*',route=>route.abort());
  await page.getByRole('button',{name:'Search',exact:true}).click();
  const dialog=page.getByRole('dialog',{name:'Search',exact:true}),query=dialog.getByRole('combobox');
  const bounds=await dialog.boundingBox();expect(bounds.x).toBeGreaterThanOrEqual(0);expect(bounds.x+bounds.width).toBeLessThanOrEqual(320);expect(bounds.height).toBeLessThanOrEqual(640*.68);
  await query.fill('Atomic');await expect(dialog.getByRole('status')).toHaveText('Live search is unavailable. Press Enter to search the catalogue.');
  await query.press('Enter');await expect(page).toHaveURL(/discover\.php\?q=Atomic$/);await expect(page.locator('.book-card__title').filter({hasText:'Atomic Habits'})).toBeVisible();
});

for(const width of [320,1920])test(`reading scale fits edge viewport ${width}px`,async({page,context},testInfo)=>{
  await page.setViewportSize({width,height:width<768?740:1080});await signIn(page,context,'light');
  for(const [name,route] of routes){
    await page.goto(route);await page.evaluate(()=>document.fonts.ready);
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`${name} must fit ${width}px`).toBe(true);
    if(width===320)expect(await page.locator('.tabbar__label').evaluateAll(labels=>labels.every(e=>e.scrollWidth<=e.clientWidth)),`${name} navigation labels must remain readable`).toBe(true);
    if(name==='dashboard'){
      const shelf=page.locator('.reading-covers');
      if(width===320)expect(await shelf.evaluate(e=>e.scrollWidth>e.clientWidth)).toBe(true);
    }
    if(name==='book')await expect(page.getByRole('link',{name:'Listen to preview',exact:true})).toBeVisible();
    await page.screenshot({path:testInfo.outputPath(`${name}-${width}-light.png`),fullPage:true,animations:'disabled'});
  }
});
