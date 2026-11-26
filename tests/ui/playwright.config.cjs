const {defineConfig}=require('@playwright/test');
const path=require('node:path');
const fs=require('node:fs');
const baseURL=process.env.BOOKTRACK_UI_URL||'http://127.0.0.1:8115';
if(!['127.0.0.1','localhost'].includes(new URL(baseURL).hostname))throw Error('UI checks require a disposable loopback library.');
const fixture=process.env.BOOKTRACK_SQLITE,allowed=path.resolve(__dirname,'../runtime')+path.sep;
if(!fixture||!path.resolve(fixture).startsWith(allowed)||!fixture.endsWith('.sqlite')||!fs.existsSync(fixture))throw Error('Set BOOKTRACK_SQLITE to an existing disposable tests/runtime/*.sqlite fixture.');
module.exports=defineConfig({
  testDir:__dirname,testMatch:'**/*.spec.cjs',workers:1,timeout:90000,
  outputDir:path.resolve(__dirname,'../runtime/ui-results'),
  reporter:[['list'],['html',{outputFolder:path.resolve(__dirname,'../runtime/ui-report'),open:'never'}]],
  use:{baseURL,browserName:'chromium',reducedMotion:'reduce',trace:'retain-on-failure',screenshot:'only-on-failure'},
  webServer:{command:'php -d display_errors=0 -S 127.0.0.1:8115',cwd:path.resolve(__dirname,'../..'),url:baseURL+'/login.php',reuseExistingServer:false,timeout:30000}
});
