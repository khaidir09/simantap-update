// Patch bundle frontend: kartu "Pusat Analisis" di dashboard ADMIN / Kepala Sekolah.
// Bundle asli menampilkan kalimat contoh yang ditanam keras ("...mencapai 85%. Terdapat 4 guru...").
// Patch ini menggantinya dengan angka nyata dari endpoint get_dashboard_analytics.
// Spesifikasi: docs/specs/pusat-analisis-kepsek.md
// Prasyarat: patch penugasan mapel (apply-penugasan-mapel-bundle.js) sudah diterapkan.
// Pemakaian (dari root deploy): node docs/patches/apply-pusat-analisis-bundle.js
// File sumber TIDAK diubah; hasil ditulis ke salinan baru dan app.html diarahkan ke salinan itu.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC_NAME = 'index-CGWL69P7-mapel1.js';
const OUT_NAME = 'index-CGWL69P7-kepsek1.js';
const SRC_SHA256 = '7cbe511cac9852bb6608134c3163bd57524cb3c6502748d634e30f49d4df4129';

const srcPath = path.join(ROOT, 'assets', SRC_NAME);
const outPath = path.join(ROOT, 'assets', OUT_NAME);
const appHtmlPath = path.join(ROOT, 'app.html');

function fail(msg) {
  console.error('GAGAL: ' + msg);
  process.exit(1);
}

const src = fs.readFileSync(srcPath, 'utf8');
const hash = crypto.createHash('sha256').update(fs.readFileSync(srcPath)).digest('hex');
if (hash !== SRC_SHA256) fail(`hash ${SRC_NAME} berbeda (${hash}). Bundle sudah berubah, cari ulang anchor secara manual.`);

// --- P1: komponen isi kartu Pusat Analisis -------------------------------
// Disisipkan di scope modul yang sama dengan dashboard (ape), jadi ue (React),
// a (runtime jsx), nr (URL api/index.php) dan jf (ikon panah) sudah tersedia.
const HOST_ANCHOR = 'function ape({onQuickAction:e,user:t}){';

const ANALYTICS_COMPONENT = `function smtPusatAnalisis({onOpen:e}){const[n,r]=ue.useState(null),[s,i]=ue.useState("memuat");ue.useEffect(()=>{let o=!0;return(async()=>{try{const l=await fetch(\`\${nr}?action=get_dashboard_analytics&_t=\${Date.now()}\`,{credentials:"same-origin"});if(!l.ok)throw new Error("HTTP "+l.status);const c=await l.json();o&&(r(c),i("siap"))}catch(l){console.error("Pusat Analisis:",l),o&&i("gagal")}})(),()=>{o=!1}},[]);const o=l=>{if(!l)return"";const c=["Jan","Feb","Mar","Apr","Mei","Jun","Jul","Agu","Sep","Okt","Nov","Des"],[u,h,p]=String(l).split(" ")[0].split("-");return\`\${parseInt(p,10)} \${c[parseInt(h,10)-1]||h} \${u}\`};if(s==="memuat")return a.jsx("p",{className:"text-[13px] text-slate-400 font-medium relative z-10",children:"Memuat data analisis…"});if(s==="gagal"||!n)return a.jsx("p",{className:"text-[13px] text-slate-400 font-medium relative z-10",children:"Data analisis belum bisa dimuat."});const l=n.total_guru>0?\`\${n.guru_mengisi} dari \${n.total_guru} guru sudah mengisi jurnal hari ini\`:"Belum ada akun guru terdaftar",c=n.total_guru>0&&n.guru_belum>0?\`\${n.guru_belum} guru belum mengisi.\`:"",u=n.jurnal_belum_verifikasi>0?\`\${n.jurnal_belum_verifikasi} jurnal menunggu verifikasi.\`:"",h=n.jurnal_hari_ini===0&&n.jurnal_terakhir?\`Jurnal terakhir masuk \${o(n.jurnal_terakhir)}.\`:"";return a.jsxs("div",{className:"relative z-10",children:[a.jsxs("p",{className:"text-4xl font-black text-slate-900 tracking-tight leading-none",children:[n.persen,"%"]}),a.jsx("p",{className:"text-[10px] text-slate-400 font-bold uppercase tracking-[0.15em] mt-2",children:"Pengisian Jurnal Hari Ini"}),a.jsxs("p",{className:"text-[13px] text-slate-600 font-medium leading-relaxed mt-4",children:[l,". ",c]}),a.jsxs("p",{className:"text-[11px] text-slate-400 font-medium leading-relaxed mt-2",children:[n.jurnal_hari_ini," jurnal masuk hari ini • ",n.jurnal_7_hari," dalam 7 hari terakhir. ",h," ",u]})]})}`;

// --- P2: ganti kalimat contoh dengan komponen di atas ---------------------
const DUMMY_TEXT = 'a.jsx("p",{className:"text-[13px] text-slate-600 font-medium leading-relaxed relative z-10",children:\'"Aktivitas pengisian jurnal guru hari ini mencapai 85%. Terdapat 4 guru yang belum melakukan presensi."\'})';
const REAL_CONTENT = 'a.jsx(smtPusatAnalisis,{onOpen:e})';

const patches = [
  {
    id: 'A1 Sisipkan komponen smtPusatAnalisis sebelum komponen dashboard',
    from: HOST_ANCHOR,
    to: ANALYTICS_COMPONENT + HOST_ANCHOR,
  },
  {
    id: 'A2 Kartu Pusat Analisis: ganti kalimat contoh dengan data database',
    from: DUMMY_TEXT,
    to: REAL_CONTENT,
  },
];

let out = src;
for (const p of patches) {
  const count = out.split(p.from).length - 1;
  if (count !== 1) fail(`${p.id}: anchor ditemukan ${count} kali (harus 1).`);
  out = out.replace(p.from, () => p.to);
  console.log('OK  ' + p.id);
}

fs.writeFileSync(outPath, out, 'utf8');
console.log(`Ditulis: assets/${OUT_NAME}`);
console.log('SHA-256 hasil: ' + crypto.createHash('sha256').update(fs.readFileSync(outPath)).digest('hex'));

const html = fs.readFileSync(appHtmlPath, 'utf8');
if (html.includes(`./assets/${OUT_NAME}`)) {
  console.log('app.html sudah mereferensikan bundle patch.');
} else {
  const ref = `./assets/${SRC_NAME}`;
  if (html.split(ref).length - 1 !== 1) fail(`app.html tidak mereferensikan ${ref} tepat 1 kali.`);
  fs.writeFileSync(appHtmlPath, html.replace(ref, `./assets/${OUT_NAME}`), 'utf8');
  console.log(`app.html diarahkan ke assets/${OUT_NAME}`);
}
