const {test,expect}=require('@playwright/test');
const path=require('node:path');
const {execFileSync}=require('node:child_process');
let fixture;
const fixtureScript=path.join(__dirname,'reader_fixture.php');
test.beforeAll(()=>{fixture=JSON.parse(execFileSync('php',[fixtureScript],{encoding:'utf8'}));});
test.afterAll(()=>{if(fixture)execFileSync('php',[fixtureScript,'cleanup',JSON.stringify(fixture)]);});
for(const [width,theme] of [[320,'light'],[390,'light'],[390,'dark'],[1440,'light']]){
  test(`reader navigation and scroll handoff ${width}px ${theme}`,async({page,context},testInfo)=>{
    const height=width<768?844:1000,errors=[];page.on('pageerror',error=>errors.push(error.message));
    await page.setViewportSize({width,height});
    await context.addCookies([{name:'bt_theme',value:theme,url:test.info().project.use.baseURL||'http://127.0.0.1:8115'}]);
    await page.goto('/login.php');await page.getByLabel('Username',{exact:true}).fill('alice');await page.getByLabel('Password',{exact:true}).fill('member123');await page.getByRole('button',{name:'Sign in',exact:true}).click();await expect(page).toHaveURL(/discover\.php$/);
    await page.goto('/reader.php?id=15');
    const stage=page.locator('[data-reader-stage]'),paper=page.locator('[data-reader-paper]'),number=page.getByRole('spinbutton',{name:'Page number',exact:true}),zoom=page.getByRole('combobox',{name:'Zoom',exact:true});
    const bottom=page.getByRole('group',{name:'Page navigation below the book',exact:true});
    await expect(zoom).toBeEnabled();await expect(zoom).toHaveValue('page');
    await number.fill('1');await number.press('Tab');await expect(paper).toHaveAttribute('aria-label','Page 1');
    await expect(page.locator('.reader-toolbar')).toHaveCount(0);await expect(page.getByRole('spinbutton',{name:'Page number',exact:true})).toHaveCount(1);await expect(bottom.getByRole('spinbutton',{name:'Page number',exact:true})).toBeVisible();
    await expect(page.locator('[data-reader-status]')).toHaveClass(/is-quiet/);
    await expect(bottom.getByRole('button',{name:'Previous page',exact:true})).toBeDisabled();
    expect((await bottom.boundingBox()).y).toBeGreaterThanOrEqual((await stage.boundingBox()).y+(await stage.boundingBox()).height);
    expect(await stage.evaluate(e=>e.scrollHeight<=e.clientHeight+2)).toBe(true);
    await bottom.getByRole('button',{name:'Next page',exact:true}).click();await expect(number).toHaveValue('2');await expect(paper).toHaveAttribute('aria-label','Page 2');
    const aligned=await stage.boundingBox();expect(aligned.y).toBeGreaterThanOrEqual(0);expect(aligned.y).toBeLessThan(120);
    await number.fill('3');await number.press('Tab');await expect(paper).toHaveAttribute('aria-label','Page 3');await expect(bottom.getByRole('button',{name:'Next page',exact:true})).toBeDisabled();
    await bottom.getByRole('button',{name:'Previous page',exact:true}).click();await expect(paper).toHaveAttribute('aria-label','Page 2');
    await zoom.selectOption('1.5');
    await expect.poll(()=>stage.evaluate(e=>e.scrollHeight>e.clientHeight+100)).toBe(true);
    await stage.focus();await page.keyboard.press('End');await expect.poll(()=>stage.evaluate(e=>Math.abs(e.scrollHeight-e.clientHeight-e.scrollTop))).toBeLessThan(3);
    const view=await stage.boundingBox();await page.mouse.move(view.x+Math.min(view.width/2,width/2),Math.min(height-140,view.y+view.height/2));
    const before=await page.evaluate(()=>scrollY);await page.mouse.wheel(0,400);
    await expect.poll(()=>page.evaluate(()=>scrollY)).toBeGreaterThan(before+10);
    await zoom.selectOption('page');await expect.poll(()=>stage.evaluate(e=>e.scrollHeight<=e.clientHeight+2)).toBe(true);
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
    await page.screenshot({path:testInfo.outputPath(`reader-${width}-${theme}.png`),fullPage:true,animations:'disabled'});
    await expect.poll(async()=>{
      const response=await page.request.get('/api/reading.php?book_id=15&medium=ebook');return (await response.json()).progress?.position;
    }).toBe(2);
    await page.reload();await expect(paper).toHaveAttribute('aria-label','Page 2');
    expect(errors).toEqual([]);
  });
}
