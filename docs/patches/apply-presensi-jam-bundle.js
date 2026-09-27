// Patch bundle frontend: Presensi jam sebelumnya di form jurnal.
// Dokumentasi: docs/patches/presensi-jam-sebelumnya.md
//  S0 Hook __pjsPakaiJurnal + peta class __pjsK, disisipkan tepat sebelum komponen
//     __pnkPantauNilaiKelas (patch Pantau Nilai).
//  S1 Form jurnal: pasang hook; perubahan status oleh guru ditandai manual.
//  S2 Form jurnal: keterangan "Izin di jam 1-3 (Matematika)" di bawah nama siswa.
//  S3 Form jurnal: pemberitahuan jumlah siswa yang diisi otomatis di atas daftar kehadiran.
// Prasyarat: patch Jadwal KBM (apply-jadwal-kbm-bundle.js) sudah diterapkan.
// Pemakaian (dari root deploy): node docs/patches/apply-presensi-jam-bundle.js
// File sumber TIDAK diubah; hasil ditulis ke salinan baru dan app.html diarahkan ke salinan itu.
// Tailwind di bundle sudah terkompilasi: skrip menolak class yang tidak ada di CSS bundle.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC_NAME = 'index-CGWL69P7-jadwal1.js';
const OUT_NAME = 'index-CGWL69P7-presensijam1.js';
const SRC_SHA256 = '1ff756464bd98e51d9cb7acdbc9db3136871792cf2886e6ae7cc05b21cf17f86';
const CSS_NAME = 'index-CC1PBzaO.css';

const srcPath = path.join(ROOT, 'assets', SRC_NAME);
const outPath = path.join(ROOT, 'assets', OUT_NAME);
const cssPath = path.join(ROOT, 'assets', CSS_NAME);
const appHtmlPath = path.join(ROOT, 'app.html');

function fail(msg) {
  console.error('GAGAL: ' + msg);
  process.exit(1);
}

const src = fs.readFileSync(srcPath, 'utf8');
const hash = crypto.createHash('sha256').update(fs.readFileSync(srcPath)).digest('hex');
if (hash !== SRC_SHA256) fail(`hash ${SRC_NAME} berbeda (${hash}). Bundle sudah berubah, cari ulang anchor secara manual.`);
if (src.includes('__pjs')) fail(`prefiks identifier __pjs sudah dipakai di ${SRC_NAME}.`);

// ---------------------------------------------------------------------------
// Semua class Tailwind (divalidasi terhadap CSS bundle di bawah).
// ---------------------------------------------------------------------------
const KELAS = {
  ketSakit: 'text-[10px] font-bold text-blue-600',
  ketIzin: 'text-[10px] font-bold text-amber-600',
  banner: 'mb-4 p-3 rounded-xl bg-amber-50 border border-amber-100 text-amber-700 text-xs font-bold',
};

// ---------------------------------------------------------------------------
// S0 Hook. Ditulis sebagai fungsi biasa lalu disisipkan lewat toString().
// Identifier bebas dari scope modul bundle: ue = React, K$ = URL api/index.php.
// ---------------------------------------------------------------------------
/* eslint-disable no-undef */

// Hook form jurnal: ambil siswa yang SAKIT/IZIN di jam sebelumnya (atau presensi MANUAL wali kelas)
// lalu isi statusnya otomatis untuk jurnal baru, hanya bila siswa masih HADIR bawaan dan belum
// diubah guru. Isian otomatis yang tidak berlaku lagi (jam/tanggal diubah) dikembalikan ke HADIR.
function __pjsPakaiJurnal({ kelas, tanggal, jam, siswa, edit, setPresensi }) {
  const [info, setInfo] = ue.useState({});
  const otomatis = ue.useRef({});
  const manual = ue.useRef({});

  ue.useEffect(() => { otomatis.current = {}; manual.current = {}; }, [kelas, tanggal]);

  ue.useEffect(() => {
    if (edit || !kelas || !tanggal) { setInfo({}); return undefined; }
    let batal = false;
    const pewaktu = setTimeout(async () => {
      let items = {};
      try {
        const q = `class_id=${encodeURIComponent(kelas)}&date=${encodeURIComponent(tanggal)}&jam=${encodeURIComponent(jam || '')}`;
        const res = await fetch(`${K$}?action=presensi_jam_sebelumnya&${q}`);
        const j = res.ok ? await res.json() : null;
        if (j && j.items && typeof j.items === 'object') items = j.items;
      } catch (e) { items = {}; }
      if (batal) return;
      const ada = new Set((siswa || []).map((s) => String(s.id)));
      const saran = {};
      Object.keys(items).forEach((sid) => {
        const it = items[sid];
        if (!ada.has(sid) || !it || (it.status !== 'SAKIT' && it.status !== 'IZIN')) return;
        const nama = it.status === 'SAKIT' ? 'Sakit' : 'Izin';
        saran[sid] = {
          status: it.status,
          teks: it.sumber === 'MANUAL' ? `${nama} menurut ${it.keterangan}` : `${nama} di ${it.keterangan}`,
        };
      });
      setInfo(saran);
      const lama = otomatis.current;
      const baruOtomatis = {};
      setPresensi((p) => {
        const baru = { ...p };
        Object.keys(lama).forEach((sid) => {
          if (manual.current[sid] || baru[sid] !== lama[sid]) return;
          if (saran[sid] && saran[sid].status === lama[sid]) baruOtomatis[sid] = lama[sid];
          else baru[sid] = 'HADIR';
        });
        Object.keys(saran).forEach((sid) => {
          if (baruOtomatis[sid] || manual.current[sid] || baru[sid] !== 'HADIR') return;
          baru[sid] = saran[sid].status;
          baruOtomatis[sid] = saran[sid].status;
        });
        return baru;
      });
      otomatis.current = baruOtomatis;
    }, 400);
    return () => { batal = true; clearTimeout(pewaktu); };
  }, [kelas, tanggal, jam, siswa, edit]);

  return {
    info,
    jumlah: Object.keys(info).length,
    manual: (sid) => { manual.current[sid] = true; },
  };
}
/* eslint-enable no-undef */

const KOMPONEN_SRC = `const __pjsK=${JSON.stringify(KELAS)};${__pjsPakaiJurnal.toString()}`;

const patches = [
  {
    id: 'S0 hook presensi jam sebelumnya',
    from: 'function __pnkPantauNilaiKelas(',
    to: `${KOMPONEN_SRC}function __pnkPantauNilaiKelas(`,
  },
  {
    id: 'S1 hook di form jurnal + flag manual',
    from: '__dspJ=__dspPakaiJurnal({kelas:y.kelas,tanggal:y.tanggal,jam:y.jam,jumlahSiswa:ke.length,edit:!!n,setPresensi:R}),Pn=(Ge,at)=>{__dspJ.manual(Ge),R(',
    to: '__dspJ=__dspPakaiJurnal({kelas:y.kelas,tanggal:y.tanggal,jam:y.jam,jumlahSiswa:ke.length,edit:!!n,setPresensi:R}),'
      + '__pjsJ=__pjsPakaiJurnal({kelas:y.kelas,tanggal:y.tanggal,jam:y.jam,siswa:ke,edit:!!n,setPresensi:R}),'
      + 'Pn=(Ge,at)=>{__dspJ.manual(Ge),__pjsJ.manual(Ge),R(',
  },
  {
    id: 'S2 keterangan jam sebelumnya di bawah nama siswa',
    from: 'children:Ge.name}),__dspJ.info[Ge.id]&&',
    to: 'children:Ge.name}),__pjsJ.info[Ge.id]&&a.jsx("p",{className:__pjsJ.info[Ge.id].status==="SAKIT"?__pjsK.ketSakit:__pjsK.ketIzin,children:__pjsJ.info[Ge.id].teks}),__dspJ.info[Ge.id]&&',
  },
  {
    id: 'S3 pemberitahuan isi otomatis',
    from: '" Langkah 3a: Daftar Kehadiran Siswa"]}),',
    to: '" Langkah 3a: Daftar Kehadiran Siswa"]}),__pjsJ.jumlah>0&&a.jsx("p",{className:__pjsK.banner,'
      + 'children:`${__pjsJ.jumlah} siswa tercatat Sakit/Izin sebelumnya hari ini dan sudah diisi otomatis. Ubah bila siswa ternyata hadir di jam ini.`}),',
  },
];

// ---------------------------------------------------------------------------
const css = fs.readFileSync(cssPath, 'utf8');
const cssEscape = (c) => c.replace(/[^a-zA-Z0-9_-]/g, (ch) => '\\' + ch);
const kelas = new Set();
Object.values(KELAS).forEach((v) => v.split(/\s+/).filter(Boolean).forEach((c) => kelas.add(c)));
const hilang = [...kelas].filter((c) => {
  const re = new RegExp('\\.' + cssEscape(c).replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?![a-zA-Z0-9_\\\\-])');
  return !re.test(css);
});
if (hilang.length) fail('class Tailwind tidak ada di ' + CSS_NAME + ': ' + hilang.join(' '));
console.log(`OK  ${kelas.size} class Tailwind tersedia di CSS bundle`);

let out = src;
for (const p of patches) {
  const count = out.split(p.from).length - 1;
  if (count !== 1) fail(`${p.id}: anchor ditemukan ${count} kali (harus 1).`);
  out = out.replace(p.from, () => p.to);
  console.log('OK  ' + p.id);
}

fs.writeFileSync(outPath, out, 'utf8');
console.log(`Ditulis assets/${OUT_NAME}`);

const html = fs.readFileSync(appHtmlPath, 'utf8');
if (html.includes(`./assets/${OUT_NAME}`)) {
  console.log('app.html sudah mereferensikan bundle patch.');
} else {
  const ref = `./assets/${SRC_NAME}`;
  if (html.split(ref).length - 1 !== 1) fail(`app.html tidak mereferensikan ${ref} tepat 1 kali.`);
  fs.writeFileSync(appHtmlPath, html.replace(ref, `./assets/${OUT_NAME}`), 'utf8');
  console.log(`app.html diarahkan ke assets/${OUT_NAME}`);
}
