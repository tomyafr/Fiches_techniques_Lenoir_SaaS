const puppeteer = require('puppeteer');

(async () => {
    const browser = await puppeteer.launch({ headless: "new", args: ['--no-sandbox'] });
    const page = await browser.newPage();
    
    page.on('console', msg => console.log('PAGE LOG:', msg.text()));
    page.on('pageerror', error => console.log('PAGE ERROR:', error.message));
    page.on('response', response => {
        if (!response.ok()) {
            console.log('PAGE RESPONSE ERROR:', response.status(), response.url());
        }
    });

    try {
        await page.goto('http://127.0.0.1:8080/auto_login.php', { waitUntil: 'networkidle0' });
        console.log("Page loaded successfully.");
        
        // Wait for signatures to initialize
        await page.waitForTimeout(1000);
        
        // Click finaliser button
        await page.evaluate(() => {
            const btn = document.querySelector('.btn-final');
            if (btn) btn.click();
            else console.log('btn-final not found');
        });
        
        await page.waitForTimeout(500);

    } catch (err) {
        console.error("Puppeteer error:", err);
    }
    
    await browser.close();
})();
