const {test,expect}=require('@playwright/test');
for(const [width,theme] of [[320,'light'],[390,'dark'],[1440,'light']]){
  test(`All books remains available while filtering ${width}px ${theme}`,async({page,context},testInfo)=>{
    await page.setViewportSize({width,height:width<768?844:1000});
    await context.addCookies([{name:'bt_theme',value:theme,url:test.info().project.use.baseURL||'http://127.0.0.1:8115'}]);
    await page.goto('/login.php');await page.getByLabel('Username',{exact:true}).fill('alice');await page.getByLabel('Password',{exact:true}).fill('member123');await page.getByRole('button',{name:'Sign in',exact:true}).click();await expect(page).toHaveURL(/discover\.php$/);
    await page.goto('/discover.php?view=all&sort=title&available=1');
    const categories=page.getByRole('navigation',{name:'Categories',exact:true}),all=categories.getByRole('link',{name:'All books',exact:true});
    for(const category of ['Business','Self-help']){
      await categories.getByRole('link',{name:new RegExp('^'+category+' ')}).click();
      await expect(page).toHaveURL(new RegExp('category='+encodeURIComponent(category)));
      await expect(page.getByRole('heading',{name:'All books',exact:true})).toBeVisible();
      await expect(categories.getByRole('link',{name:new RegExp('^'+category+' ')})).toHaveAttribute('aria-current','true');
      await expect(all).toBeInViewport();
      expect(new URL(page.url()).searchParams.get('sort')).toBe('title');expect(new URL(page.url()).searchParams.get('available')).toBe('1');
      const reset=new URL(await all.getAttribute('href'),page.url());expect(reset.searchParams.has('category')).toBe(false);expect(reset.searchParams.get('view')).toBe('all');
      await page.screenshot({path:testInfo.outputPath(`category-${category}-${width}-${theme}.png`),fullPage:true,animations:'disabled'});
      await all.click();await expect(all).toHaveAttribute('aria-current','true');expect(new URL(page.url()).searchParams.has('category')).toBe(false);
    }
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
  });
}
