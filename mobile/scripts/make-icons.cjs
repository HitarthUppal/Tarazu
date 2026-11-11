/* Renders the Tarazu scale mark into the source images @capacitor/assets needs (assets/*.png). */
const sharp = require('sharp'); const fs = require('fs'); const path = require('path');
const A = path.join(__dirname, '../assets'); const mark = fs.readFileSync(path.join(A, 'scale.svg.txt'), 'utf8');
const GREEN = '#1F6B4A';
const svg = (size, scale, bg) => `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">${bg ? `<rect width="100%" height="100%" fill="${bg}"/>` : ''}<g transform="translate(${size / 2 - 512 * scale},${size / 2 - 512 * scale}) scale(${scale})">${mark}</g></svg>`;
(async () => {
  const out = (f, s) => sharp(Buffer.from(s)).png().toFile(path.join(A, f));
  await out('icon-only.png', svg(1024, 0.86, GREEN));                       // iOS and legacy Android
  await out('icon-foreground.png', svg(1024, 0.62, null));                   // Android adaptive: inside the 66% safe zone
  await out('icon-background.png', `<svg xmlns="http://www.w3.org/2000/svg" width="1024" height="1024"><rect width="100%" height="100%" fill="${GREEN}"/></svg>`);
  await out('splash.png', svg(2732, 0.6, GREEN));
  await out('splash-dark.png', svg(2732, 0.6, '#0E3B2E'));
  console.log('icons rendered');
})();
