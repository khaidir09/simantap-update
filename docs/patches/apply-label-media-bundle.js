// Patch bundle frontend: Label Media / Alat Pembelajaran di form jurnal.
// Dokumentasi: docs/patches/label-media-jurnal.md
//  S0 Komponen __lmdPilihMedia + peta class __lmdK, disisipkan tepat sebelum komponen
//     __pnkPantauNilaiKelas (patch Pantau Nilai).
//  S1 Form jurnal: input teks "Media / Alat Pembelajaran" diganti komponen label.
// Prasyarat: patch Presensi Jam Sebelumnya (apply-presensi-jam-bundle.js) sudah diterapkan.
// Pemakaian (dari root deploy): node docs/patches/apply-label-media-bundle.js
// File sumber TIDAK diubah; hasil ditulis ke salinan baru dan app.html diarahkan ke salinan itu.
// Tailwind di bundle sudah terkompilasi: skrip menolak class yang tidak ada di CSS bundle.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC_NAME = 'index-CGWL69P7-presensijam1.js';
const OUT_NAME = 'index-CGWL69P7-labelmedia1.js';
const SRC_SHA256 = '737e2e887007b4196cd633ecc4d08ec825ae81da149c2b728145a4b6d954255c';
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
if (src.includes('__lmd')) fail(`prefiks identifier __lmd sudah dipakai di ${SRC_NAME}.`);

// ---------------------------------------------------------------------------
// Semua class Tailwind (divalidasi terhadap CSS bundle di bawah).
// ---------------------------------------------------------------------------
const KELAS = {
  wadah: 'space-y-3',
  daftar: 'flex flex-wrap gap-2',
  chip: 'inline-flex items-center gap-1 px-3 py-1 rounded-full border text-xs font-bold transition-all',
  chipPilih: 'bg-blue-600 border-blue-600 text-white',
  chipBiasa: 'bg-white border-slate-200 text-slate-600 hover:border-blue-300',
  chipHapus: 'bg-white border-red-200 text-red-600',
  baris: 'flex gap-2',
  input: 'flex-1 min-w-0 bg-slate-50 border border-slate-100 rounded-2xl p-3 outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-300 transition-all font-bold text-slate-700 text-sm',
  tombol: 'px-4 rounded-2xl bg-blue-50 text-blue-600 font-bold text-sm hover:bg-blue-100',
  kaki: 'flex flex-wrap items-center justify-between gap-2',
  petunjuk: 'text-[10px] font-bold text-slate-400',
  kelola: 'text-[10px] font-bold text-blue-600 hover:underline',
};

// Cadangan bila media_label_list gagal dimuat; samakan dengan LABEL_MEDIA_BAWAAN di api/label_media.php.
const BAWAAN = [
  'Papan Tulis', 'Spidol', 'Buku Paket', 'LKPD', 'Modul Ajar', 'LCD Proyektor', 'Laptop',
  'PowerPoint', 'Video Pembelajaran', 'Internet', 'HP (Smartphone)', 'Alat Peraga',
  'Gambar & Poster', 'Speaker', 'Lab Komputer', 'Lingkungan Sekitar',
];

// ---------------------------------------------------------------------------
// S0 Komponen. Ditulis sebagai fungsi biasa lalu disisipkan lewat toString().
// Identifier bebas dari scope modul bundle: ue = React, a = jsx runtime, K$ = URL api/index.php.
// ---------------------------------------------------------------------------
/* eslint-disable no-undef */

// Pilihan label Media / Alat Pembelajaran. Nilai tetap teks dipisah koma ("LCD Proyektor, LKPD"),
// sama seperti isian lama. Label yang diketik manual disimpan ke daftar label guru.
function __lmdPilihMedia({ nilai, ubah }) {
  const K = __lmdK;
  const h = (t, p, ...c) => {
    const { key, ...rest } = p || {};
    if (c.length) rest.children = c.length === 1 ? c[0] : c;
    return a.jsx(t, rest, key);
  };
  const rapikan = (x) => String(x == null ? '' : x).replace(/\s+/g, ' ').trim().replace(/^[,;\s]+|[,;\s]+$/g, '').slice(0, 60);
  const pecah = (t) => String(t == null ? '' : t).split(/[,;\n]+/).map(rapikan).filter(Boolean);
  const kunci = (x) => x.toLowerCase();
  const kirim = (action, label) => fetch(`${K$}?action=${action}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ label }),
  }).catch(() => null);

  const [daftar, setDaftar] = ue.useState({ bawaan: __lmdBawaan, milik: [] });
  const [ketik, setKetik] = ue.useState('');
  const [kelola, setKelola] = ue.useState(false);

  ue.useEffect(() => {
    let batal = false;
    (async () => {
      try {
        const res = await fetch(`${K$}?action=media_label_list`);
        const j = res.ok ? await res.json() : null;
        if (!batal && j && Array.isArray(j.bawaan) && Array.isArray(j.milik)) setDaftar({ bawaan: j.bawaan, milik: j.milik });
      } catch (e) { /* tetap pakai label bawaan */ }
    })();
    return () => { batal = true; };
  }, []);

  const dipilih = pecah(nilai);
  const kunciPilih = new Set(dipilih.map(kunci));
  const semua = [];
  const ada = new Set();
  [...daftar.bawaan, ...daftar.milik, ...dipilih].forEach((l) => {
    if (ada.has(kunci(l))) return;
    ada.add(kunci(l));
    semua.push(l);
  });

  const pilih = (label) => {
    if (kelola) {
      kirim('media_label_hapus', label);
      setDaftar((d) => ({
        bawaan: d.bawaan.filter((x) => kunci(x) !== kunci(label)),
        milik: d.milik.filter((x) => kunci(x) !== kunci(label)),
      }));
      return;
    }
    const k = kunci(label);
    ubah((kunciPilih.has(k) ? dipilih.filter((x) => kunci(x) !== k) : [...dipilih, label]).join(', '));
  };

  const tambah = () => {
    const baru = pecah(ketik);
    setKetik('');
    if (!baru.length) return;
    const hasil = [...dipilih];
    const sudah = new Set(kunciPilih);
    const kenal = new Set([...daftar.bawaan, ...daftar.milik].map(kunci));
    const labelBaru = [];
    baru.forEach((l) => {
      const k = kunci(l);
      if (!sudah.has(k)) { sudah.add(k); hasil.push(l); }
      if (!kenal.has(k)) { kenal.add(k); labelBaru.push(l); }
      kirim('media_label_simpan', l);
    });
    if (labelBaru.length) setDaftar((d) => ({ ...d, milik: [...labelBaru, ...d.milik] }));
    ubah(hasil.join(', '));
  };

  return h('div', { className: K.wadah },
    h('div', { className: K.daftar },
      semua.map((l) => {
        const on = kunciPilih.has(kunci(l));
        const gaya = kelola ? K.chipHapus : on ? K.chipPilih : K.chipBiasa;
        return h('button', {
          key: kunci(l), type: 'button', onClick: () => pilih(l),
          className: `${K.chip} ${gaya}`,
          title: kelola ? 'Sembunyikan label ini dari daftar Anda' : on ? 'Klik untuk membatalkan' : 'Klik untuk memilih',
        }, kelola ? `✕ ${l}` : on ? `✓ ${l}` : l);
      })),
    !kelola && h('div', { className: K.baris },
      h('input', {
        type: 'text', value: ketik, className: K.input,
        placeholder: 'Ketik media lain...',
        onChange: (e) => setKetik(e.target.value),
        onKeyDown: (e) => { if (e.key === 'Enter') { e.preventDefault(); tambah(); } },
        onBlur: tambah,
      }),
      h('button', { type: 'button', className: K.tombol, onMouseDown: (e) => e.preventDefault(), onClick: tambah }, '+ Tambah')),
    h('div', { className: K.kaki },
      h('p', { className: K.petunjuk },
        kelola
          ? 'Klik label untuk menyembunyikannya dari daftar. Label muncul lagi bila diketik ulang.'
          : 'Klik label untuk memilih. Media yang diketik manual otomatis tersimpan sebagai label untuk jurnal berikutnya.'),
      h('button', { type: 'button', className: K.kelola, onClick: () => setKelola(!kelola) }, kelola ? 'Selesai' : 'Kelola label')));
}
/* eslint-enable no-undef */

const KOMPONEN_SRC = `const __lmdK=${JSON.stringify(KELAS)};const __lmdBawaan=${JSON.stringify(BAWAAN)};${__lmdPilihMedia.toString()}`;

const patches = [
  {
    id: 'S0 komponen label media',
    from: 'function __pnkPantauNilaiKelas(',
    to: `${KOMPONEN_SRC}function __pnkPantauNilaiKelas(`,
  },
  {
    id: 'S1 form jurnal: input media diganti label',
    from: 'a.jsx("input",{type:"text",name:"alatMedia",value:y.alatMedia,onChange:Yt,placeholder:"cth. Proyektor, Buku Cetak",className:"w-full bg-slate-50 border border-slate-100 rounded-2xl p-3 outline-none focus:ring-2 focus:ring-blue-100 focus:border-blue-300 transition-all font-bold text-slate-700 text-sm"})',
    to: 'a.jsx(__lmdPilihMedia,{nilai:y.alatMedia,ubah:__lmdV=>Yt({target:{name:"alatMedia",value:__lmdV}})})',
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
