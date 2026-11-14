// Patch bundle frontend: Impor Instrumen Digital Guru Wali (Google Form "Formulir Awal Murid Guru Wali").
// Spesifikasi: docs/specs/impor-instrumen-guru-wali.md (§5). Catatan patch: docs/patches/instrumen-guru-wali.md
//  I0 Komponen (__igwBacaFile, __igwImpor, __igwJawaban, helper) disisipkan sebelum __gwKartuProfil.
//  I1 Murid Dampingan: __gwDampingan diganti versi baru (kolom "Formulir", ringkasan "X dari Y", tombol + modal impor).
//  I2 Profil Murid 360° tab Identitas: "Jarak / transportasi menurut murid" bila biodata Dapodik kosong.
//  I3 Profil Murid 360° Asesmen: Kompetensi RIASEC + kegiatan dikuasai; cerita kesulitan, mapel pilihan, bakat,
//     keterampilan ingin ditingkatkan; label sumber "formulir murid".
//  I4 Profil Murid 360° Rencana Karir: mapel pendukung, alasan memilih rencana.
//  I5 Profil Murid 360°: kartu "Jawaban Murid — Formulir Awal" (lipat) setelah Rencana Karir.
// Prasyarat: patch Guru Wali (apply-guru-wali-bundle.js) sudah diterapkan.
// Pemakaian (dari root deploy): node docs/patches/apply-instrumen-guru-wali-bundle.js
// File sumber TIDAK diubah; hasil ditulis ke salinan baru dan app.html diarahkan ke salinan itu.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..', '..');
const SRC_NAME = 'index-CGWL69P7-guruwali1.js';
const OUT_NAME = 'index-CGWL69P7-instrumengw1.js';
const SRC_SHA256 = '2fd0fd13c3c1a6be1a7fcc9c8158b0627782dd1dccaf7d66a913b905cada381c';

function fail(msg) {
  console.error('GAGAL: ' + msg);
  process.exit(1);
}

// ---------------------------------------------------------------------------
// I0 Komponen. Ditulis sebagai fungsi biasa lalu disisipkan lewat toString().
// Identifier bebas dari scope modul bundle:
//   a = jsx runtime, ue = React, zi = kartu judul halaman, C_ = XLSX.read, Jl = XLSX.utils,
//   dari patch Profil Murid: __pmK (class), __pmH, __pmTanggal, __pmAngka, __pmIsi, __pmBuka, __pmUnduh,
//   dari patch Guru Wali: __gwK, __gwKirim, __gwGalat, __gwAmbil,
//   ikon lucide: If (upload), wa (users), Ti (download), Z1 (triangle-alert), po (refresh-cw), pi (x).
// Hanya memakai class dari __pmK/__gwK (sudah divalidasi patch sebelumnya).
// ---------------------------------------------------------------------------
/* eslint-disable no-undef */
const __igwStatusLabel = { BARU: 'Baru', TIMPA: 'Timpa', LEWATI: 'Dilewati', GALAT: 'Galat' };

// 'YYYY-MM-DD HH:MM:SS' -> 'dd/mm/yyyy HH:MM'
function __igwWaktu(s) {
  const m = /^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}))?/.exec(String(s || ''));
  if (!m) return '-';
  return `${m[3]}/${m[2]}/${m[1]}${m[4] ? ` ${m[4]}:${m[5]}` : ''}`;
}

// Baca sheet pertama apa adanya: Cap waktu .xlsx tetap serial Excel (tak ambigu), CSV tetap teks
// (opsi raw:true mencegah SheetJS menebak tanggal CSV dengan format bulan/hari).
async function __igwBacaFile(file) {
  if (file.size > 2 * 1024 * 1024) throw new Error('File terlalu besar (maksimal 2 MB).');
  const buf = await file.arrayBuffer();
  const wb = C_(new Uint8Array(buf), { type: 'array', raw: true });
  const ws = wb.Sheets[wb.SheetNames[0]];
  if (!ws) throw new Error('File tidak berisi sheet.');
  const rows = Jl.sheet_to_json(ws, { header: 1, raw: true, defval: '' }).map((r) => r.map((v) => {
    if (v === null || v === undefined || typeof v === 'boolean') return '';
    if (typeof v === 'number') return Number.isInteger(v) ? v.toFixed(0) : String(v);
    return String(v);
  }));
  while (rows.length && rows[rows.length - 1].every((v) => String(v).trim() === '')) rows.pop();
  if (!rows.length) throw new Error('File kosong.');
  return rows;
}

// Modal impor hasil formulir: pilih file -> pratinjau -> simpan
function __igwImpor({ onTutup, onSelesai }) {
  const K = __pmK;
  const h = __pmH;
  const [tahap, setTahap] = ue.useState('pilih');
  const [file, setFile] = ue.useState(null);
  const [rows, setRows] = ue.useState(null);
  const [hasil, setHasil] = ue.useState(null);
  const [sibuk, setSibuk] = ue.useState(false);
  const [galat, setGalat] = ue.useState('');

  const kirim = async (r, mode, nama) => {
    const res = await __gwKirim('instrumen_gw_impor', { mode, rows: r, nama_file: nama });
    if (!res.ok) throw new Error(__gwGalat(res));
    return res.json;
  };
  const pratinjau = async (f) => {
    setGalat(''); setSibuk(true); setFile(f); setHasil(null);
    try {
      const r = await __igwBacaFile(f);
      setRows(r);
      setHasil(await kirim(r, 'pratinjau', f.name)); setTahap('pratinjau');
    } catch (e) { setGalat(e.message); } finally { setSibuk(false); }
  };
  const simpan = async () => {
    setGalat(''); setSibuk(true);
    try {
      const j = await kirim(rows, 'simpan', file ? file.name : '');
      setHasil(j); setTahap('selesai');
      if (onSelesai) onSelesai(j);
    } catch (e) { setGalat(e.message); } finally { setSibuk(false); }
  };
  const baris = hasil && Array.isArray(hasil.baris) ? hasil.baris : [];
  const jmlTulis = baris.filter((b) => b.status === 'BARU' || b.status === 'TIMPA').length;
  const warna = (s) => (s === 'BARU' ? K.badgeHijau : s === 'TIMPA' ? K.badgeBiru : s === 'LEWATI' ? K.badgeAbu : K.badgeMerah);
  const GAYA = { VISUAL: 'Visual', AUDITORI: 'Auditori', KINESTETIK: 'Kinestetik' };
  const unduhMasalah = () => {
    const masalah = baris.filter((b) => b.status === 'LEWATI' || b.status === 'GALAT');
    __pmUnduh([['Baris', 'NISN', 'Nama di formulir', 'Status', 'Keterangan'],
      ...masalah.map((b) => [b.baris, b.nisn || '', b.nama_form || '', __igwStatusLabel[b.status] || b.status, [...(b.pesan || []), ...(b.peringatan || [])].join('; ')])],
      'Laporan-Impor-Formulir-Bermasalah.xlsx', 'Bermasalah');
  };

  const isiPilih = h('div', { className: K.modalBody },
    h('div', { className: K.info },
      h('p', null, 'Unggah sheet respons Google Form "Formulir Awal Murid Guru Wali" tanpa diubah.'),
      h('p', null, 'Di Google Sheets respons: File → Download → Microsoft Excel (.xlsx). Format .csv juga diterima, tetapi .xlsx lebih aman untuk tanggal.'),
      h('p', null, 'Hanya murid dampingan aktif Anda yang mengisi pada semester aktif yang akan disimpan. Skor minat & kompetensi RIASEC serta gaya belajar dihitung otomatis.')),
    h('input', { type: 'file', accept: '.xlsx,.xls,.csv', className: K.field, disabled: sibuk,
      onChange: (e) => { const f = e.target.files && e.target.files[0]; if (f) pratinjau(f); e.target.value = ''; } }),
    sibuk && h('p', { className: K.spinnerTeks }, 'Membaca file…'));

  const hasilKolom = (b) => (b.status === 'BARU' || b.status === 'TIMPA'
    ? h('div', null,
      h('span', { className: K.nisn }, `Minat: ${b.riasec_kode || '-'} · Kompetensi: ${b.kompetensi_kode || '-'}`),
      h('span', { className: K.nisn }, `Gaya belajar: ${GAYA[b.gaya_belajar] || '-'}`))
    : '-');
  const isiHasil = hasil && h('div', { className: K.modalBody },
    h('p', { className: K.teksKecil }, `Periode aktif: ${hasil.academic_year} ${hasil.semester === 'GENAP' ? 'Genap' : 'Ganjil'}`),
    h('div', { className: K.ringkas },
      ...[['baru', 'Baru'], ['timpa', 'Timpa'], ['lewati', 'Dilewati'], ['galat', 'Galat'], ['total', 'Total']].map(([k, l]) =>
        h('span', { key: k, className: K.chip }, `${l}: ${(hasil.ringkasan || {})[k] || 0}`))),
    (hasil.peringatan_file || []).length > 0 && h('div', { className: K.peringatan }, ...hasil.peringatan_file.map((p, i) => h('p', { key: i }, p))),
    tahap === 'selesai' && h('div', { className: __gwK.ok }, `Selesai. ${hasil.disimpan || 0} hasil formulir disimpan.`),
    h('div', { className: K.tabelWrap },
      h('table', { className: K.tabel },
        h('thead', { className: K.thead }, h('tr', null,
          h('th', { className: K.th }, 'Baris'), h('th', { className: K.th }, 'Murid'), h('th', { className: K.th }, 'Diisi'),
          h('th', { className: K.th }, 'Hasil'), h('th', { className: K.th }, 'Status'), h('th', { className: K.th }, 'Keterangan'))),
        h('tbody', { className: K.tbody }, ...baris.map((b) => h('tr', { key: b.baris, className: K.tr },
          h('td', { className: K.td }, b.baris),
          h('td', { className: K.tdNama },
            h('span', { className: K.kvNilai }, b.nama || b.nama_form || '-'),
            b.nama && b.nama_form && b.nama !== b.nama_form && h('span', { className: K.nisn }, `Di formulir: ${b.nama_form}`),
            h('span', { className: K.nisn }, `NISN ${b.nisn || '-'}${b.kelas ? ` · ${b.kelas}` : ''}`)),
          h('td', { className: __gwK.tdNowrap }, b.cap_waktu ? __igwWaktu(b.cap_waktu) : '-'),
          h('td', { className: K.td }, hasilKolom(b)),
          h('td', { className: K.td }, h('span', { className: warna(b.status) }, __igwStatusLabel[b.status] || b.status)),
          h('td', { className: K.td },
            ...(b.pesan || []).map((p, i) => h('div', { key: 'p' + i, className: b.status === 'GALAT' ? K.daftarPesanMerah : K.daftarPesan }, p)),
            ...(b.peringatan || []).map((p, i) => h('div', { key: 'w' + i, className: K.daftarPesan }, `⚠ ${p}`)))))))));

  return h('div', { className: K.overlay, onClick: (e) => { if (e.target === e.currentTarget && !sibuk) onTutup(); } },
    h('div', { className: K.modal },
      h('div', { className: K.modalHead },
        h('p', { className: K.subjudul }, 'Impor Hasil Formulir (Google Form)'),
        h('button', { type: 'button', className: K.tutup, onClick: onTutup, disabled: sibuk, 'aria-label': 'Tutup' }, a.jsx(pi, { size: 18 }))),
      galat && h('div', { className: K.modalBody }, h('div', { className: K.galat }, a.jsx(Z1, { size: 18 }), galat)),
      tahap === 'pilih' ? isiPilih : isiHasil,
      h('div', { className: K.modalFoot },
        tahap !== 'pilih' && baris.some((b) => b.status === 'LEWATI' || b.status === 'GALAT')
          && h('button', { type: 'button', className: K.btnPutih, onClick: unduhMasalah }, a.jsx(Ti, { size: 14 }), 'Unduh baris bermasalah'),
        tahap === 'pratinjau' && h('button', { type: 'button', className: K.btnPutih, disabled: sibuk, onClick: () => { setTahap('pilih'); setHasil(null); } }, 'Pilih file lain'),
        tahap === 'pratinjau' && h('button', { type: 'button', className: K.btnHijau, disabled: sibuk || jmlTulis === 0, onClick: simpan },
          sibuk ? 'Menyimpan…' : `Simpan ${jmlTulis} hasil`),
        h('button', { type: 'button', className: K.btnPutih, disabled: sibuk, onClick: onTutup }, tahap === 'selesai' ? 'Tutup' : 'Batal'))));
}

// Kartu "Jawaban Murid — Formulir Awal" di Profil Murid 360° (lipat)
function __igwJawaban({ data }) {
  const K = __pmK;
  const h = __pmH;
  const [buka, setBuka] = ue.useState(false);
  const [pilih, setPilih] = ue.useState(0);
  const daftar = data.instrumen || [];
  if (!daftar.length) return null;
  const r = daftar[Math.min(pilih, daftar.length - 1)];
  const jawaban = r.jawaban || {};
  const judul = r.judul || {};
  const kunci = Object.keys(jawaban);
  const minat = kunci.filter((k) => /^A4[01]_/.test(k)).sort();
  const lain = kunci.filter((k) => !/^A4[01]_/.test(k));
  const semLabel = (s) => (s === 'GENAP' ? 'Genap' : 'Ganjil');
  return h('div', { className: K.card },
    h('div', { className: K.cardHead },
      h('div', null,
        h('p', { className: K.subjudul }, 'Jawaban Murid — Formulir Awal'),
        h('p', { className: K.teksKecil }, `Diisi ${__igwWaktu(r.cap_waktu)} · diimpor oleh ${r.guru_nama || '-'} · ${__igwWaktu(r.diimpor_pada)}`)),
      h('div', { className: K.barKiri },
        daftar.length > 1 && h('select', { className: K.select, value: pilih, onChange: (e) => setPilih(Number(e.target.value)) },
          ...daftar.map((x, i) => h('option', { key: x.id, value: i }, `${x.academic_year} ${semLabel(x.semester)}`))),
        h('button', { type: 'button', className: K.btnPutih, onClick: () => setBuka((v) => !v) }, buka ? 'Sembunyikan jawaban' : 'Lihat jawaban'))),
    buka && h('div', { className: K.cardBody },
      h('div', { className: K.kv }, ...lain.map((k) => h('div', { key: k },
        h('span', { className: K.kvLabel }, `[${k}] ${judul[k] || ''}`),
        h('span', { className: K.kvNilai }, __pmIsi(jawaban[k]))))),
      minat.length > 0 && h('p', { className: K.judulKecil }, 'Pernyataan minat (42 butir)'),
      minat.length > 0 && h('div', { className: K.kv }, ...minat.map((k) => h('div', { key: k, className: K.barKiri },
        h('span', { className: String(jawaban[k]).toLowerCase() === 'suka' ? K.badgeHijau : K.badgeAbu }, __pmIsi(jawaban[k])),
        h('span', { className: K.teks }, `${k.slice(4)}. ${judul[k] || ''}`))))));
}

// Halaman Murid Dampingan (GURU) — versi dengan kolom Formulir dan impor hasil formulir
function __gwDampingan({ onNavigate }) {
  const K = __pmK;
  const h = __pmH;
  const [data, setData] = ue.useState(null);
  const [galat, setGalat] = ue.useState('');
  const [cari, setCari] = ue.useState('');
  const [impor, setImpor] = ue.useState(false);
  const muat = ue.useCallback(async () => {
    setGalat('');
    try { setData(await __gwAmbil('guru_wali_dampingan_saya')); } catch (e) { setGalat(e.message); }
  }, []);
  ue.useEffect(() => { muat(); }, [muat]);

  const kepala = a.jsx(zi, {
    title: 'Murid Dampingan',
    description: 'Murid yang Anda dampingi sebagai guru wali, dari semua kelas. Klik nama untuk membuka Profil Murid 360°.',
    category: 'Bimbingan Guru Wali',
    color: 'blue',
    illustration: a.jsx(wa, { size: 70, className: K.ilustrasi }),
  });
  let isi;
  if (galat) isi = h('div', { className: K.galat }, a.jsx(Z1, { size: 18 }), galat);
  else if (!data) isi = h('div', { className: K.spinnerWrap }, h('div', { className: K.spinner }), h('p', { className: K.spinnerTeks }, 'Memuat murid dampingan…'));
  else if (!data.murid.length) isi = h('div', { className: K.kosong }, 'Anda belum memiliki murid dampingan. Penugasan guru wali diatur oleh Kepala Sekolah/Admin.');
  else {
    const kunci = cari.trim().toLowerCase();
    const murid = data.murid.filter((m) => !kunci || `${m.name} ${m.nisn} ${m.class_name}`.toLowerCase().includes(kunci));
    const p = data.periode_aktif;
    const terisi = data.ringkasan && typeof data.ringkasan.instrumen_terisi === 'number'
      ? data.ringkasan.instrumen_terisi : data.murid.filter((m) => m.instrumen_cap_waktu).length;
    isi = h('div', { className: K.card },
      h('div', { className: K.cardHead },
        h('div', null,
          h('p', { className: K.judulKecil }, `${data.murid.length} murid dampingan · kehadiran & asesmen ${p.academic_year} ${p.semester === 'GENAP' ? 'Genap' : 'Ganjil'}`),
          h('p', { className: K.teksKecil }, `${terisi} dari ${data.murid.length} murid sudah mengisi formulir semester ini`)),
        h('input', { className: K.input, placeholder: 'Cari nama/NISN/kelas', value: cari, onChange: (e) => setCari(e.target.value) })),
      h('div', { className: K.tabelWrap }, h('table', { className: K.tabel },
        h('thead', { className: K.thead }, h('tr', null,
          h('th', { className: K.th }, 'Murid'), h('th', { className: K.th }, 'Kelas'), h('th', { className: K.th }, 'Didampingi sejak'),
          h('th', { className: K.thTengah }, 'Biodata'), h('th', { className: K.thTengah }, 'Formulir'), h('th', { className: K.thTengah }, 'Asesmen'),
          h('th', { className: K.thTengah }, 'Poin'), h('th', { className: K.thTengah }, '% Hadir'))),
        h('tbody', { className: K.tbody }, ...murid.map((m) => h('tr', { key: m.id, className: K.tr },
          h('td', { className: K.tdNama },
            h('button', { type: 'button', className: K.namaBtn, onClick: () => __pmBuka(m.id, 'murid-dampingan', onNavigate) }, m.name),
            h('span', { className: K.nisn }, `NISN ${m.nisn || '-'}`)),
          h('td', { className: K.td }, m.class_name),
          h('td', { className: __gwK.tdNowrap }, __pmTanggal(m.tanggal_mulai)),
          h('td', { className: K.tdTengah }, h('span', { className: m.ada_biodata ? K.badgeHijau : K.badgeAbu }, m.ada_biodata ? '✓' : '–')),
          h('td', { className: K.tdTengah }, m.instrumen_cap_waktu
            ? h('span', { className: K.badgeHijau, title: `Diisi ${__igwWaktu(m.instrumen_cap_waktu)}` }, `Sudah (${__igwWaktu(m.instrumen_cap_waktu).slice(0, 10)})`)
            : h('span', { className: K.badgeAbu }, 'Belum')),
          h('td', { className: K.tdTengah }, h('span', { className: m.ada_asesmen ? K.badgeHijau : K.badgeAbu }, m.ada_asesmen ? '✓' : '–')),
          h('td', { className: K.tdTengah }, h('span', { className: m.total_violation_points > 0 ? K.badgeMerah : K.badgeAbu }, m.total_violation_points)),
          h('td', { className: K.tdTengah }, m.persen_hadir === null ? '-' : `${__pmAngka(m.persen_hadir)}%`)))))),
      !murid.length && h('div', { className: K.kosongKecil }, 'Tidak ada murid yang cocok.'));
  }
  const bisaImpor = data && data.murid && data.murid.length > 0;
  return h('div', { className: K.halaman }, kepala,
    h('div', { className: K.bar },
      bisaImpor ? h('button', { type: 'button', className: K.btnHijau, onClick: () => setImpor(true) }, a.jsx(If, { size: 14 }), 'Impor Hasil Formulir (Google Form)') : h('span', null),
      h('button', { type: 'button', className: K.btnPutih, onClick: () => { setData(null); muat(); } }, a.jsx(po, { size: 14 }), 'Muat ulang')),
    isi,
    impor && a.jsx(__igwImpor, { onTutup: () => setImpor(false), onSelesai: () => muat() }));
}
/* eslint-enable no-undef */

const FUNGSI = [__igwWaktu, __igwBacaFile, __igwImpor, __igwJawaban];
const KOMPONEN_SRC = `const __igwStatusLabel=${JSON.stringify(__igwStatusLabel)};${FUNGSI.map((f) => f.toString()).join('')}`;

// Potongan yang disisipkan ke dalam __pmProfil (memakai variabel lokal: data, b, asesmen, kv, chips, batang, TIPE, K, h)
const JARAK_LAMA = "      kv('Jarak ke sekolah', b && b.jarak_rumah_km !== null ? `${__pmAngka(b.jarak_rumah_km)} km` : null),";
const JARAK_BARU = JARAK_LAMA + "\n      (data.instrumen || []).length > 0 && (!b || !b.alat_transportasi || b.jarak_rumah_km === null)"
  + " && kv('Jarak / transportasi menurut murid', `${__pmIsi(data.instrumen[0].jarak_kategori)} / ${__pmIsi(data.instrumen[0].transportasi)}`),";

const RIASEC_LAMA = "adaSkorRiasec && h('div', { className: K.wrap }, ...['R', 'I', 'A', 'S', 'E', 'C'].map((c) => batang(`${c} ${TIPE[c]}`, asesmen['riasec_' + c.toLowerCase()])))),";
const RIASEC_BARU = "adaSkorRiasec && h('div', { className: K.wrap }, ...['R', 'I', 'A', 'S', 'E', 'C'].map((c) => batang(`${c} ${TIPE[c]}`, asesmen['riasec_' + c.toLowerCase()]))),"
  + " asesmen.kompetensi_kode && h('div', { className: K.wrap },"
  + " h('div', null, h('span', { className: K.kvLabel }, 'Kompetensi RIASEC (kegiatan yang dikuasai)'),"
  + " h('p', { className: K.kode }, asesmen.kompetensi_kode),"
  + " h('p', { className: K.teksKecil }, asesmen.kompetensi_kode.split('').map((c) => TIPE[c]).filter(Boolean).join(' · '))),"
  + " ...['R', 'I', 'A', 'S', 'E', 'C'].map((c) => batang(`${c} ${TIPE[c]}`, asesmen['kompetensi_' + c.toLowerCase()], true)),"
  + " h('div', null, h('span', { className: K.kvLabel }, 'Kegiatan yang paling bisa dilakukan'), chips(asesmen.kegiatan_dikuasai)))),";

const MAPEL_LAMA = "        h('div', null, h('span', { className: K.kvLabel }, 'Mapel tersulit'), chips(asesmen.mapel_tersulit)),";
const MAPEL_BARU = MAPEL_LAMA
  + "\n        asesmen.kesulitan_cerita && kv('Cerita kesulitan (dari murid)', asesmen.kesulitan_cerita),"
  + "\n        asesmen.mapel_pilihan && asesmen.mapel_pilihan.length > 0 && h('div', null, h('span', { className: K.kvLabel }, 'Mapel pilihan'), chips(asesmen.mapel_pilihan)),"
  + "\n        asesmen.bakat && kv('Bakat / keterampilan dimiliki', asesmen.bakat),"
  + "\n        asesmen.keterampilan_target && kv('Keterampilan ingin ditingkatkan', asesmen.keterampilan_target),";

const SUMBER_LAMA = "${asesmen.sumber === 'IMPOR' ? 'impor Excel' : 'input manual'}";
const SUMBER_BARU = "${asesmen.sumber === 'IMPOR' ? 'impor Excel' : asesmen.sumber === 'INSTRUMEN' ? 'formulir murid (Google Form)' : 'input manual'}";

const KARIR_LAMA = "      kv('Catatan', asesmen.catatan_karir)));";
const KARIR_BARU = "      asesmen.mapel_pendukung && asesmen.mapel_pendukung.length > 0 && h('div', null, h('span', { className: K.kvLabel }, 'Mapel pendukung karir'), chips(asesmen.mapel_pendukung)),"
  + "\n      asesmen.alasan_rencana && kv('Alasan memilih rencana', asesmen.alasan_rencana),"
  + "\n" + KARIR_LAMA;

const LAYOUT_LAMA = '    kepala, bagianBiodata, a.jsx(__gwKartuProfil, { data }), bagianAsesmen, bagianKarir,';
const LAYOUT_BARU = '    kepala, bagianBiodata, a.jsx(__gwKartuProfil, { data }), bagianAsesmen, bagianKarir, a.jsx(__igwJawaban, { data }),';

const patches = [
  { id: 'I0 komponen instrumen', from: 'function __gwKartuProfil(', to: `${KOMPONEN_SRC}function __gwKartuProfil(` },
  { id: 'I2 identitas: jarak/transportasi menurut murid', from: JARAK_LAMA, to: JARAK_BARU },
  { id: 'I3a asesmen: kompetensi RIASEC', from: RIASEC_LAMA, to: RIASEC_BARU },
  { id: 'I3b asesmen: butir baru', from: MAPEL_LAMA, to: MAPEL_BARU },
  { id: 'I3c asesmen: label sumber', from: SUMBER_LAMA, to: SUMBER_BARU },
  { id: 'I4 rencana karir: mapel pendukung & alasan', from: KARIR_LAMA, to: KARIR_BARU },
  { id: 'I5 kartu jawaban murid', from: LAYOUT_LAMA, to: LAYOUT_BARU },
];

function main() {
  const srcPath = path.join(ROOT, 'assets', SRC_NAME);
  const outPath = path.join(ROOT, 'assets', OUT_NAME);
  const appHtmlPath = path.join(ROOT, 'app.html');

  const src = fs.readFileSync(srcPath, 'utf8');
  const hash = crypto.createHash('sha256').update(fs.readFileSync(srcPath)).digest('hex');
  if (hash !== SRC_SHA256) fail(`hash ${SRC_NAME} berbeda (${hash}). Bundle sudah berubah, cari ulang anchor secara manual.`);

  // Komponen hanya boleh memakai kunci __pmK/__gwK yang ada
  const pmK = /const __pmK=(\{.*?\});/.exec(src);
  const gwK = /const __gwK=(\{.*?\});/.exec(src);
  if (!pmK || !gwK) fail('__pmK/__gwK (patch Profil Murid/Guru Wali) tidak ditemukan.');
  const kunciPm = new Set(Object.keys(JSON.parse(pmK[1])));
  const kunciGw = new Set(Object.keys(JSON.parse(gwK[1])));
  const semuaSisip = KOMPONEN_SRC + JARAK_BARU + RIASEC_BARU + MAPEL_BARU + KARIR_BARU + __gwDampingan.toString();
  const tidakAda = [...new Set([...semuaSisip.matchAll(/\bK\.([A-Za-z0-9_]+)/g)].map((m) => m[1]))].filter((k) => !kunciPm.has(k));
  if (tidakAda.length) fail('kunci __pmK tidak ada: ' + tidakAda.join(', '));
  const tidakAdaG = [...new Set([...semuaSisip.matchAll(/\b__gwK\.([A-Za-z0-9_]+)/g)].map((m) => m[1]))].filter((k) => !kunciGw.has(k));
  if (tidakAdaG.length) fail('kunci __gwK tidak ada: ' + tidakAdaG.join(', '));

  for (const nama of ['__igwImpor', '__igwJawaban', 'instrumen_gw_impor']) {
    if (src.includes(nama)) fail(`${nama} sudah ada di ${SRC_NAME}.`);
  }
  const wajib = {
    If: /[,;\s]If=Nt\("upload"/, wa: /[,;\s]wa=Nt\("users"/, Ti: /[,;\s]Ti=Nt\("download"/, Z1: /[,;\s]Z1=Nt\("triangle-alert"/,
    po: /[,;\s]po=Nt\("refresh-cw"/, pi: /[,;\s]pi=Nt\("x"/, C_: /function C_\(/, Jl: /[,;\s]Jl=\{encode_col:/,
    __pmH: /function __pmH\(/, __pmIsi: /function __pmIsi\(/, __pmTanggal: /function __pmTanggal\(/, __pmAngka: /function __pmAngka\(/,
    __pmBuka: /function __pmBuka\(/, __pmUnduh: /function __pmUnduh\(/, __gwKirim: /function __gwKirim\(/, __gwGalat: /function __gwGalat\(/,
    __gwAmbil: /function __gwAmbil\(/,
  };
  for (const [nama, re] of Object.entries(wajib)) if (!re.test(src)) fail(`identifier bundle ${nama} tidak ditemukan.`);

  let out = src;
  // I1: ganti seluruh fungsi __gwDampingan lama (antara awal fungsinya dan __gwKartuProfil)
  const awal = 'function __gwDampingan({ onNavigate }) {';
  const akhir = 'function __gwKartuProfil(';
  if (out.split(awal).length - 1 !== 1 || out.split(akhir).length - 1 !== 1) fail('I1: anchor __gwDampingan/__gwKartuProfil tidak tepat 1 kali.');
  const i0 = out.indexOf(awal);
  const i1 = out.indexOf(akhir);
  if (i1 < i0) fail('I1: urutan fungsi tidak sesuai.');
  const lama = out.slice(i0, i1);
  if (!lama.includes("'murid-dampingan'") || lama.length > 6000) fail('I1: isi __gwDampingan lama tidak dikenali.');
  out = out.slice(0, i0) + __gwDampingan.toString() + out.slice(i1);
  console.log('OK  I1 Murid Dampingan: kolom Formulir + impor');

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
}

if (require.main === module) main();
