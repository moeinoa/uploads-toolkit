/* Uploads Toolkit — admin UI (vanilla JS) */
(function () {
	'use strict';
	const U = window.UTK;
	const root = document.getElementById('utk');
	if (!U || !root) return;

	/* ---------------------------------------------------------- helpers */
	const E = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const N = (n) => Number(n || 0).toLocaleString('en-US');
	const B = (n) => {
		n = Number(n || 0);
		if (n < 1024) return n + ' B';
		const u = ['KB', 'MB', 'GB', 'TB'];
		let i = -1;
		do { n /= 1024; i++; } while (n >= 1024 && i < 3);
		return n.toFixed(n >= 100 ? 0 : 1) + ' ' + u[i];
	};
	const DT = (ts) => (ts ? new Date(ts * 1000).toLocaleString('fa-IR') : '—');
	const L = {
		status: { used: 'در حال استفاده', inactive: 'فقط پیش‌نویس/زباله‌دان', unused: 'بلااستفاده' },
		kind: { image: 'تصویر', video: 'ویدیو', audio: 'صوت', pdf: 'PDF', doc: 'سند', archive: 'فشرده', other: 'سایر' },
		src: { media: 'کتابخانه‌ی رسانه', file: 'خارج از کتابخانه' },
		flag: { r: 'ثبت‌شده', s: 'اضافه (ثبت‌نشده)', a: 'webp/avif ثبت‌نشده', u: 'ارجاع دارد' },
		mode: { group: 'حذف گروه', stale: 'نسخه‌های اضافه', delete: 'حذف دائمی', trash: 'انتقال به سطل', restore: 'بازگردانی', purge: 'پاک‌سازی سطل' },
	};
	const groupLabel = (by, k, g) => {
		if (by === 'status') return L.status[k] || k;
		if (by === 'kind') return L.kind[k] || k;
		if (by === 'src') return L.src[k] || k;
		if (by === 'parent') return k === '0' || k === 0 ? 'بدون والد (Unattached)' : 'پیوست‌شده به: ' + (g.label || '#' + k);
		if (by === 'n') return k + ' نسخه';
		return k;
	};

	const api = (op, data = {}) => {
		const fd = new FormData();
		fd.append('action', 'utk');
		fd.append('op', op);
		fd.append('nonce', U.nonce);
		Object.keys(data).forEach((k) => {
			const v = data[k];
			fd.append(k, v !== null && typeof v === 'object' ? JSON.stringify(v) : v);
		});
		return fetch(U.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then((r) => r.text())
			.then((t) => {
				let j;
				try { j = JSON.parse(t); } catch (e) { throw new Error('پاسخ نامعتبر از سرور (احتمالاً timeout یا خطای PHP).'); }
				if (!j.success) throw new Error((j.data && j.data.message) || 'خطا');
				return j.data;
			});
	};

	function modal(title, cancelable) {
		const ov = document.createElement('div');
		ov.className = 'utk-ov';
		ov.innerHTML = '<div class="utk-md" dir="rtl"><h3>' + E(title) + '</h3><div class="utk-bar"><i></i></div><p class="utk-mt"></p><div class="utk-ml"></div><p class="utk-mb"><button type="button" class="button utk-mx" hidden>لغو</button> <button type="button" class="button button-primary utk-mc" hidden>بستن</button></p></div>';
		document.body.appendChild(ov);
		const q = (s) => ov.querySelector(s);
		const o = {
			cancelled: false,
			set(text, pct) { q('.utk-mt').textContent = text; if (pct != null) q('.utk-bar i').style.width = pct + '%'; },
			log(html) { const d = document.createElement('div'); d.innerHTML = html; q('.utk-ml').appendChild(d); },
			done() { q('.utk-mx').hidden = true; q('.utk-mc').hidden = false; return new Promise((r) => { q('.utk-mc').onclick = () => { ov.remove(); r(); }; }); },
			close() { ov.remove(); },
		};
		if (cancelable) { const x = q('.utk-mx'); x.hidden = false; x.onclick = () => { o.cancelled = true; x.disabled = true; }; }
		return o;
	}

	/* ------------------------------------------------------------ state */
	const defF = () => ({ status: '', src: '', kind: '', ym: '', q: '', min_mb: '', min_n: '', stale: 0, alt: 0, dup: 0, old: 0 });
	const S = { tab: 'overview', ov: null, f: defF(), sort: 'bytes', dir: 'desc', group: '', page: 1, per: 100, sel: new Map(), selAll: false, list: null, dupPage: 1 };

	root.innerHTML =
		'<nav class="utk-tabs">' +
		[['overview', 'نمای کلی'], ['explorer', 'فایل‌ها'], ['dups', 'تکراری‌ها'], ['trash', 'سطل بازیافت'], ['settings', 'تنظیمات و گزارش']]
			.map((t) => '<a href="#" data-tab="' + t[0] + '">' + t[1] + '</a>').join('') +
		'</nav><div id="utk-p"></div>';
	const P = () => document.getElementById('utk-p');

	function show(tab, preset) {
		S.tab = tab;
		root.querySelectorAll('.utk-tabs a').forEach((a) => a.classList.toggle('on', a.dataset.tab === tab));
		if (tab === 'explorer' && preset) { S.f = Object.assign(defF(), preset); S.page = 1; S.sel.clear(); S.selAll = false; }
		({ overview: vOverview, explorer: vExplorer, dups: vDups, trash: vTrash, settings: vSettings })[tab]();
	}

	/* ---------------------------------------------------------- overview */
	async function vOverview() {
		P().innerHTML = '<p>در حال بارگذاری…</p>';
		let ov;
		try { ov = await api('overview'); } catch (e) { P().innerHTML = '<div class="notice notice-error"><p>' + E(e.message) + '</p></div>'; return; }
		S.ov = ov;
		const sc = ov.scan, S_ = ov.S;
		let h = '';
		if (ov.changed && ov.meta && ov.changed > ov.meta.finished) h += '<div class="notice notice-warning inline"><p>پس از آخرین اسکن تغییراتی (حذف/بازگردانی) انجام شده؛ برای نتایج دقیق دوباره اسکن کنید.</p></div>';
		h += '<div class="utk-card"><h2>اسکن</h2>';
		if (sc.status === 'running') {
			h += '<p>یک اسکن نیمه‌کاره هست (' + E(sc.label) + ' — ' + sc.pct + '%).</p><p><button class="button button-primary" data-act="scan-resume">ادامه‌ی اسکن</button> <button class="button" data-act="scan-cancel">لغو</button></p>';
		} else {
			h += '<p>' + (ov.has ? 'آخرین اسکن: <b>' + E(DT(ov.meta.finished)) + '</b> (مدت: ' + ov.meta.duration + ' ثانیه)' : 'هنوز اسکنی انجام نشده.') + '</p>' +
				'<p><label><input type="checkbox" id="utk-hash" ' + (ov.settings.hash ? 'checked' : '') + '> محاسبه‌ی هش برای یافتن فایل‌های تکراری</label> ' +
				'<select id="utk-algo">' + U.algos.map((a) => '<option' + (a === ov.settings.algo ? ' selected' : '') + '>' + a + '</option>').join('') + '</select></p>' +
				'<p><button class="button button-primary" data-act="scan-start">' + (ov.has ? 'اسکن مجدد' : 'شروع اسکن') + '</button></p>';
		}
		h += '<p class="description">اسکن فقط می‌خواند. مسیر داده‌ها و سطل: <code dir="ltr">' + E(ov.trash_dir) + '</code></p></div>';

		if (!ov.has) { P().innerHTML = h; return; }
		const st = S_.st;
		h += '<div class="utk-grid">' +
			card('حجم کل uploads', B(ov.disk.all[1]), N(ov.disk.all[0]) + ' فایل') +
			card('تحلیل‌شده', B(S_.bytes), N(S_.groups) + ' فایل منطقی / ' + N(S_.files) + ' نسخه') +
			card('در حال استفاده', B(st.used[2]), N(st.used[0]) + ' فایل منطقی', 'ok') +
			card('بلااستفاده', B(st.unused[2]), N(st.unused[0]) + ' فایل منطقی / ' + N(st.unused[1]) + ' نسخه', 'bad') +
			card('فقط پیش‌نویس/زباله‌دان', B(st.inactive[2]), N(st.inactive[0]) + ' فایل منطقی', 'warn') +
			card('نسخه‌های اضافه (stale)', B(S_.stale[1]), N(S_.stale[0]) + ' فایل', 'bad') +
			card('تقریباً قابل بازیابی', B(S_.reclaim), 'بلااستفاده + stale', 'hi') +
			card('سطل بازیافت', B(ov.trash.bytes), N(ov.trash.batches) + ' دسته') +
			'</div>';

		const f = (o) => encodeURIComponent(JSON.stringify(o));
		h += '<h2>پاکسازی سریع</h2><div class="utk-grid utk-q">' +
			qcard('نسخه‌های اضافه‌ی ثبت‌نشده', 'thumbnail های قدیمی/ثبت‌نشده‌ی فایل‌هایی که ارجاع دارند — کم‌خطرترین پاکسازی.', N(S_.stale[0]) + ' فایل — ' + B(S_.stale[1]), f({ stale: 1, old: 1 }), 'stale') +
			qcard('webp/avif ثبت‌نشده', 'کپی‌های افزونه‌های بهینه‌ساز. حذفشان کارکرد سایت را خراب نمی‌کند ولی بهینه‌سازی را از بین می‌برد.', N(S_.alt[0]) + ' فایل — ' + B(S_.alt[1]), f({ alt: 1, old: 1 }), '') +
			qcard('بلااستفاده در کتابخانه‌ی رسانه', 'هیچ ارجاعی پیدا نشد. با حذف، رکورد رسانه و همه‌ی نسخه‌ها پاک می‌شود (در سطل قابل بازگردانی).', N(S_.st.unused[0]) + ' مورد', f({ status: 'unused', src: 'media', old: 1 }), '') +
			qcard('فایل‌های بلااستفاده‌ی بیرون از کتابخانه', 'فایل‌های یتیم روی دیسک که در کتابخانه ثبت نشده‌اند.', '', f({ status: 'unused', src: 'file', old: 1 }), '') +
			'</div>';

		h += '<div class="utk-cols"><div><h2>حجم هر پوشه‌ی اصلی</h2><table class="widefat striped"><thead><tr><th>پوشه</th><th>فایل</th><th>حجم</th></tr></thead><tbody>' +
			Object.keys(ov.disk.dirs).slice(0, 15).map((k) => '<tr><td><code dir="ltr">' + E(k) + '</code></td><td>' + N(ov.disk.dirs[k][0]) + '</td><td>' + B(ov.disk.dirs[k][1]) + '</td></tr>').join('') +
			'</tbody></table><p class="description">پوشه‌های غیر از سال/ماه (مثلاً backup یا debug-log) تحلیل نمی‌شوند؛ مستقیم بررسی کنید.</p></div>' +
			'<div><h2>بر اساس سال/ماه</h2><table class="widefat striped"><thead><tr><th>پوشه</th><th>فایل منطقی</th><th>حجم</th><th>قابل بازیابی</th></tr></thead><tbody>' +
			Object.keys(S_.yms).slice(0, 15).map((k) => '<tr><td><a href="#" data-go="explorer" data-flt="' + f({ ym: k }) + '"><code dir="ltr">' + E(k) + '</code></a></td><td>' + N(S_.yms[k][0]) + '</td><td>' + B(S_.yms[k][1]) + '</td><td>' + B(S_.yms[k][2]) + '</td></tr>').join('') +
			'</tbody></table></div>' +
			'<div><h2>بر اساس نوع</h2><table class="widefat striped"><thead><tr><th>نوع</th><th>فایل منطقی</th><th>حجم</th><th>قابل بازیابی</th></tr></thead><tbody>' +
			Object.keys(S_.kinds).map((k) => '<tr><td><a href="#" data-go="explorer" data-flt="' + f({ kind: k }) + '">' + E(L.kind[k] || k) + '</a></td><td>' + N(S_.kinds[k][0]) + '</td><td>' + B(S_.kinds[k][1]) + '</td><td>' + B(S_.kinds[k][2]) + '</td></tr>').join('') +
			'</tbody></table><h2>از هر فایل چند نسخه روی دیسک هست؟</h2><table class="widefat striped"><thead><tr><th>تعداد نسخه</th><th>فایل منطقی</th><th>حجم</th></tr></thead><tbody>' +
			Object.keys(S_.hist).map((k) => '<tr><td>' + E(k) + '</td><td>' + N(S_.hist[k][0]) + '</td><td>' + B(S_.hist[k][1]) + '</td></tr>').join('') +
			'</tbody></table></div></div>';
		P().innerHTML = h;
	}
	const card = (t, v, s, c) => '<div class="utk-stat ' + (c || '') + '"><span>' + E(t) + '</span><b>' + E(v) + '</b><small>' + E(s) + '</small></div>';
	const qcard = (t, d, s, flt, quick) =>
		'<div class="utk-stat"><span>' + E(t) + '</span><small>' + E(d) + '</small><b>' + E(s) + '</b><p><a class="button" href="#" data-go="explorer" data-flt="' + flt + '">مشاهده و انتخاب</a> ' +
		(quick ? '<button class="button button-primary" data-act="quick-stale" data-flt="' + flt + '">انتقال همه به سطل</button>' : '') + '</p></div>';

	async function runScan(resume) {
		const m = modal('اسکن uploads', true);
		try {
			let s;
			if (resume) s = await api('scan_status');
			else s = await api('scan_start', { hash: document.getElementById('utk-hash').checked ? 1 : 0, algo: document.getElementById('utk-algo').value });
			while (s.status === 'running') {
				m.set(s.label + ' — ' + s.pct + '%  (مرحله ' + (s.step + 1) + ' از ' + s.total + ')', s.pct);
				if (m.cancelled) { await api('scan_cancel'); m.set('اسکن لغو شد.'); await m.done(); return show('overview'); }
				s = await api('scan_tick');
			}
			if (s.status === 'done') m.set('اسکن کامل شد (' + s.elapsed + ' ثانیه).', 100);
			else m.set('وضعیت: ' + s.status + ' ' + (s.msg || ''));
		} catch (e) { m.set('خطا: ' + e.message + ' — می‌توانید از «ادامه‌ی اسکن» ادامه دهید.'); }
		await m.done();
		show('overview');
	}

	/* ---------------------------------------------------------- explorer */
	const selOpts = (k, list) => '<select data-f="' + k + '">' + list.map((o) => '<option value="' + E(o[0]) + '"' + (String(S.f[k]) === String(o[0]) ? ' selected' : '') + '>' + E(o[1]) + '</option>').join('') + '</select>';
	function vExplorer() {
		const ym = S.ov && S.ov.S ? Object.keys(S.ov.S.yms) : [];
		P().innerHTML =
			'<div class="utk-filters">' +
			'<input type="search" data-f="q" placeholder="جستجو در عنوان/نام فایل…" value="' + E(S.f.q) + '"> ' +
			selOpts('status', [['', 'همه‌ی وضعیت‌ها'], ['unused', 'بلااستفاده'], ['inactive', 'فقط پیش‌نویس/زباله‌دان'], ['used', 'در حال استفاده']]) +
			selOpts('src', [['', 'کتابخانه + بیرون'], ['media', 'کتابخانه‌ی رسانه'], ['file', 'خارج از کتابخانه']]) +
			selOpts('kind', [['', 'همه‌ی انواع'], ['image', 'تصویر'], ['video', 'ویدیو'], ['audio', 'صوت'], ['pdf', 'PDF'], ['doc', 'سند'], ['archive', 'فشرده'], ['other', 'سایر']]) +
			'<input type="text" data-f="ym" list="utk-ym" placeholder="سال یا سال/ماه (2024 یا 2024/05)" value="' + E(S.f.ym) + '" size="22"><datalist id="utk-ym">' + ym.map((x) => '<option value="' + E(x) + '">').join('') + '</datalist> ' +
			'<input type="number" min="0" step="0.1" data-f="min_mb" placeholder="حداقل حجم (MB)" value="' + E(S.f.min_mb) + '" style="width:120px"> ' +
			'<input type="number" min="0" data-f="min_n" placeholder="حداقل نسخه" value="' + E(S.f.min_n) + '" style="width:100px"> ' +
			'<label><input type="checkbox" data-f="stale" ' + (S.f.stale ? 'checked' : '') + '> دارای نسخه‌ی اضافه</label> ' +
			'<label><input type="checkbox" data-f="alt" ' + (S.f.alt ? 'checked' : '') + '> دارای webp/avif ثبت‌نشده</label> ' +
			'<label><input type="checkbox" data-f="dup" ' + (S.f.dup ? 'checked' : '') + '> فقط تکراری‌ها</label> ' +
			'<label title="فایل‌های جدیدتر از «حداقل سن» و محافظت‌شده‌ها حذف نمی‌شوند"><input type="checkbox" data-f="old" ' + (S.f.old ? 'checked' : '') + '> فقط قابل‌حذف (قدیمی‌تر از حداقل سن)</label>' +
			'</div><div class="utk-filters">' +
			'گروه‌بندی: <select data-s="group">' + [['', 'بدون گروه‌بندی'], ['status', 'وضعیت'], ['ym', 'سال/ماه'], ['kind', 'نوع'], ['src', 'کتابخانه/بیرون'], ['parent', 'نوشته‌ی والد'], ['n', 'تعداد نسخه']].map((o) => '<option value="' + o[0] + '"' + (S.group === o[0] ? ' selected' : '') + '>' + o[1] + '</option>').join('') + '</select> ' +
			'مرتب‌سازی: <select data-s="sort">' + [['bytes', 'حجم کل'], ['reclaim', 'قابل بازیابی'], ['stale_bytes', 'حجم نسخه‌های اضافه'], ['n', 'تعداد نسخه'], ['mt', 'تاریخ'], ['title', 'عنوان']].map((o) => '<option value="' + o[0] + '"' + (S.sort === o[0] ? ' selected' : '') + '>' + o[1] + '</option>').join('') + '</select> ' +
			'<select data-s="dir"><option value="desc"' + (S.dir === 'desc' ? ' selected' : '') + '>نزولی</option><option value="asc"' + (S.dir === 'asc' ? ' selected' : '') + '>صعودی</option></select> ' +
			'<select data-s="per">' + [50, 100, 200, 500].map((n) => '<option' + (S.per === n ? ' selected' : '') + '>' + n + '</option>').join('') + '</select> در صفحه ' +
			'<button class="button" data-act="reset-f">پاک‌کردن فیلترها</button> <a class="button" data-act="csv" href="#">دانلود CSV این فهرست</a>' +
			'</div><div id="utk-sum" class="utk-sum"></div><div id="utk-list"></div><div id="utk-pg" class="utk-pg"></div><div id="utk-selbar" class="utk-selbar" hidden></div>';
		loadList();
	}

	let loadTok = 0;
	async function loadList() {
		const tok = ++loadTok;
		const box = document.getElementById('utk-list');
		if (!box) return;
		box.style.opacity = '.5';
		let d;
		try { d = await api('list', { f: S.f, sort: S.sort, dir: S.dir, group: S.group, page: S.page, per: S.per }); } catch (e) { box.innerHTML = '<div class="notice notice-error"><p>' + E(e.message) + '</p></div>'; box.style.opacity = ''; return; }
		if (tok !== loadTok) return;
		box.style.opacity = '';
		if (d.empty) { box.innerHTML = '<p>ابتدا در «نمای کلی» اسکن کنید.</p>'; return; }
		S.list = d;
		S.page = d.page;
		document.getElementById('utk-sum').innerHTML = '<b>' + N(d.total) + '</b> مورد — حجم کل <b>' + B(d.bytes) + '</b> — قابل بازیابی <b>' + B(d.reclaim) + '</b>';
		renderRows();
		const pg = document.getElementById('utk-pg');
		pg.innerHTML = d.pages > 1 ? '<button class="button" data-pg="' + (d.page - 1) + '"' + (d.page <= 1 ? ' disabled' : '') + '>قبلی</button> صفحه ' + d.page + ' از ' + d.pages + ' <button class="button" data-pg="' + (d.page + 1) + '"' + (d.page >= d.pages ? ' disabled' : '') + '>بعدی</button>' : '';
		renderSelBar();
	}

	function rowHtml(r) {
		const chk = S.sel.has(r.gid) || S.selAll;
		const badges = (r.recent ? '<span class="utk-b b-warn" title="جدیدتر از حداقل سن؛ حذف نمی‌شود">جدید</span>' : '') + (r.prot ? '<span class="utk-b b-warn">محافظت‌شده</span>' : '') + (r.stale ? '<span class="utk-b b-bad" title="نسخه‌ی اضافه">' + r.stale + ' اضافه</span>' : '') + (r.alt ? '<span class="utk-b b-mute">' + r.alt + ' webp/avif</span>' : '');
		return '<tr class="utk-row st-' + r.status + '" data-gid="' + E(r.gid) + '"><td><input type="checkbox" class="utk-sel" ' + (chk ? 'checked' : '') + (S.selAll ? ' disabled' : '') + '></td>' +
			'<td class="utk-th">' + (r.thumb ? '<img loading="lazy" src="' + E(r.thumb) + '" alt="">' : '<span>' + E(L.kind[r.kind] || '') + '</span>') + '</td>' +
			'<td class="utk-nm"><b>' + E(r.title || r.main.split('/').pop()) + '</b> ' + (r.edit ? '<a href="' + E(r.edit) + '" target="_blank">#' + r.id + '</a>' : '') + '<br><code dir="ltr">' + E(r.main) + '</code> ' + badges + '</td>' +
			'<td><span class="utk-b b-' + r.status + '">' + E(L.status[r.status]) + '</span></td>' +
			'<td><a href="#" data-more="1" title="نمایش نسخه‌ها">' + r.n + '</a></td><td>' + B(r.bytes) + '</td><td>' + (r.reclaim ? B(r.reclaim) : '—') + '</td><td><code dir="ltr">' + E(r.ym) + '</code></td></tr>';
	}
	function renderRows() {
		const d = S.list;
		let h = '<table class="widefat utk-t"><thead><tr><th><input type="checkbox" id="utk-all"></th><th></th><th>نام / مسیر</th><th>وضعیت</th><th>نسخه</th><th>حجم</th><th>قابل بازیابی</th><th>پوشه</th></tr></thead><tbody>';
		let cur = null;
		d.rows.forEach((r) => {
			if (S.group && r.grp !== cur) {
				cur = r.grp;
				const g = d.groups[r.grp] || {};
				const gAll = d.rows.filter((x) => x.grp === r.grp).every((x) => S.sel.has(x.gid));
				h += '<tr class="utk-gh"><td><input type="checkbox" class="utk-gsel" data-g="' + E(r.grp) + '"' + (gAll ? ' checked' : '') + '></td><td colspan="7"><b>' + E(groupLabel(S.group, r.grp, g)) + '</b> — ' + N(g.count) + ' مورد، ' + B(g.bytes) + '، قابل بازیابی ' + B(g.reclaim) + '</td></tr>';
			}
			h += rowHtml(r);
		});
		if (!d.rows.length) h += '<tr><td colspan="8">موردی با این فیلتر نیست.</td></tr>';
		document.getElementById('utk-list').innerHTML = h + '</tbody></table>';
		syncHeadChecks();
	}
	function syncHeadChecks() {
		const a = document.getElementById('utk-all');
		if (!a || !S.list) return;
		const rows = S.list.rows;
		a.checked = rows.length > 0 && rows.every((r) => S.sel.has(r.gid) || S.selAll);
	}
	function renderSelBar() {
		const b = document.getElementById('utk-selbar');
		if (!b) return;
		const d = S.list;
		const n = S.selAll ? d.total : S.sel.size;
		if (!n) { b.hidden = true; return; }
		let bytes = 0;
		if (S.selAll) bytes = d.reclaim; else S.sel.forEach((v) => { bytes += v.reclaim; });
		const pageAll = d.rows.length > 0 && d.rows.every((r) => S.sel.has(r.gid));
		b.hidden = false;
		b.innerHTML = '<div><b>' + N(n) + '</b> مورد انتخاب شد' + (bytes ? ' — قابل بازیابی ≈ ' + B(bytes) : '') +
			(!S.selAll && pageAll && d.total > d.rows.length ? ' — <a href="#" data-act="sel-all">انتخاب همه‌ی ' + N(d.total) + ' نتیجه‌ی فیلتر</a>' : '') +
			(S.selAll ? ' — <a href="#" data-act="sel-none">لغو انتخاب</a>' : '') + '</div>' +
			'<div><label><input type="checkbox" id="utk-perm"> حذف دائمی (بدون سطل)</label> <label><input type="checkbox" id="utk-alt"> شامل webp/avif ثبت‌نشده</label> ' +
			'<button class="button button-primary" data-act="op-stale">حذف فقط نسخه‌های اضافه</button> <button class="button button-primary" data-act="op-group">حذف کامل (بلااستفاده‌ها)</button> <button class="button" data-act="sel-none">پاک‌کردن انتخاب</button></div>';
	}

	function selectionGids() { return S.selAll ? null : Array.from(S.sel.keys()); }

	async function runOp(o) {
		let gids = o.gids || null, total = 0, bytes = 0;
		try {
			if (!gids) { const r = await api('ids', { f: o.filter }); gids = r.gids; }
		} catch (e) { alert(e.message); return; }
		if (!gids.length) { alert('موردی برای انجام نیست.'); return; }
		const what = o.mode === 'stale' ? 'نسخه‌های اضافه‌ی' + (o.alt ? ' (و webp/avif ثبت‌نشده‌ی)' : '') : 'کل فایل‌ها (به‌همراه همه‌ی نسخه‌ها و رکورد رسانه) ی';
		if (!confirm(N(gids.length) + ' مورد: ' + what + ' آن‌ها ' + (o.perm ? 'برای همیشه حذف شود؟ (غیرقابل بازگشت)' : 'به سطل بازیافت منتقل شود؟ (قابل بازگردانی)') + '\n\nموارد در حال استفاده، جدید یا محافظت‌شده به‌طور خودکار رد می‌شوند.')) return;
		let word = '';
		if (o.perm) { word = prompt('حذف دائمی غیرقابل بازگشت است. برای تأیید، کلمه‌ی DELETE را تایپ کنید:'); if (word !== 'DELETE') return; }
		let inactive = 0;
		if (o.mode === 'group' && confirm('اگر بعضی موارد فقط در پیش‌نویس/زباله‌دان ارجاع دارند، آن‌ها هم حذف شوند؟\n\nOK = بله، شامل شود    Cancel = خیر، رد شوند')) inactive = 1;
		const m = modal(o.perm ? 'حذف دائمی' : 'انتقال به سطل');
		const tot = { items: 0, files: 0, bytes: 0 }, skipped = {}, errors = [];
		let bid = '';
		try {
			bid = (await api('op_begin', { mode: o.mode, perm: o.perm ? 1 : 0, label: o.label || '', confirm: word || '' })).bid;
			const CH = o.sets ? 10 : 15;
			for (let i = 0; i < gids.length; i += CH) {
				m.set('در حال پردازش ' + N(Math.min(i + CH, gids.length)) + ' از ' + N(gids.length) + '…', Math.round(i / gids.length * 100));
				const chunk = gids.slice(i, i + CH);
				const r = o.sets
					? await api('op_run_dups', { sets: chunk, perm: o.perm ? 1 : 0, bid })
					: await api('op_run', { mode: o.mode, gids: chunk, perm: o.perm ? 1 : 0, bid, inactive, alt: o.alt ? 1 : 0 });
				['items', 'files', 'bytes'].forEach((k) => { tot[k] += r[k] || 0; });
				Object.keys(r.skipped || {}).forEach((k) => { skipped[k] = (skipped[k] || 0) + r.skipped[k]; });
				(r.errors || []).forEach((e) => errors.push(e));
			}
			await api('op_end', { perm: o.perm ? 1 : 0, bid, mode: o.mode, stats: tot, label: o.label || '' });
			m.set('تمام شد.', 100);
		} catch (e) {
			errors.push(e.message);
			m.set('عملیات متوقف شد: ' + e.message);
			try { await api('op_end', { perm: o.perm ? 1 : 0, bid, mode: o.mode, stats: tot, label: (o.label || '') + ' (ناقص)' }); } catch (e2) { /* ignore */ }
		}
		m.log('<b>' + N(tot.items) + '</b> مورد، <b>' + N(tot.files) + '</b> فایل، <b>' + B(tot.bytes) + '</b> ' + (o.perm ? 'حذف شد.' : 'به سطل منتقل شد (فضای دیسک تا خالی‌کردن سطل آزاد نمی‌شود).'));
		Object.keys(skipped).forEach((k) => m.log('رد شد: ' + N(skipped[k]) + ' × ' + E(k)));
		errors.slice(0, 20).forEach((e) => m.log('<span style="color:#b32d2e">' + E(e) + '</span>'));
		await m.done();
		S.sel.clear(); S.selAll = false;
		show(S.tab === 'overview' ? 'overview' : S.tab);
	}

	/* -------------------------------------------------------- duplicates */
	async function vDups() {
		P().innerHTML = '<p>در حال بارگذاری…</p>';
		let d;
		try { d = await api('dups', { page: S.dupPage, per: 20 }); } catch (e) { P().innerHTML = '<div class="notice notice-error"><p>' + E(e.message) + '</p></div>'; return; }
		if (d.empty) { P().innerHTML = '<p>ابتدا در «نمای کلی» اسکن کنید.</p>'; return; }
		S.dupPage = d.page;
		let h = '<div class="utk-sum"><b>' + N(d.total) + '</b> مجموعه‌ی فایل با محتوای یکسان (' + E(d.algo) + ') — صرفه‌جویی پیشنهادی ≈ <b>' + B(d.waste) + '</b>' +
			(d.hash_run ? '' : ' — <span class="utk-b b-warn">در این اسکن هش محاسبه نشده بود</span>') + '</div>' +
			'<p class="description">فقط نسخه‌های «بلااستفاده» پیشنهاد حذف می‌شوند. نسخه‌ای که در جایی استفاده می‌شود هرگز حذف نمی‌شود. قبل از حذف، محتوا دوباره با sha256 تأیید می‌شود. اگر چند کپی همگی در صفحات مختلف استفاده می‌شوند، ادغام آن‌ها دستی است (این ابزار ارجاع‌ها را بازنویسی نمی‌کند).</p>' +
			'<p><button class="button button-primary" data-act="dups-page">انتقال انتخاب‌شده‌های این صفحه به سطل</button> <button class="button" data-act="dups-all">اعمال پیشنهاد روی همه‌ی مجموعه‌ها (به سطل)</button> <label><input type="checkbox" id="utk-dperm"> حذف دائمی</label></p>';
		d.sets.forEach((s) => {
			h += '<div class="utk-set" data-h="' + E(s.h) + '"><div class="utk-sh"><code dir="ltr">' + E(s.h.slice(0, 16)) + '…</code> — ' + B(s.size) + ' × ' + s.members.length + ' — اضافی ≈ ' + B(s.waste) + '</div><table class="widefat"><tbody>';
			s.members.forEach((m) => {
				h += '<tr data-gid="' + E(m.gid) + '"><td><input type="checkbox" class="utk-dsel" ' + (m.suggest ? 'checked' : '') + (m.can ? '' : ' disabled') + '></td>' +
					'<td class="utk-th">' + (m.thumb ? '<img loading="lazy" src="' + E(m.thumb) + '" alt="">' : '') + '</td>' +
					'<td><b>' + E(m.title || m.main.split('/').pop()) + '</b> ' + (m.edit ? '<a href="' + E(m.edit) + '" target="_blank">#' + m.id + '</a>' : '') + '<br><code dir="ltr">' + E(m.main) + '</code></td>' +
					'<td><span class="utk-b b-' + m.status + '">' + E(L.status[m.status]) + '</span></td><td>' + m.n + ' نسخه</td><td>' + B(m.bytes) + '</td><td>' + (m.suggest ? 'پیشنهاد: حذف' : (m.can ? '' : 'قابل حذف نیست')) + '</td></tr>';
			});
			h += '</tbody></table></div>';
		});
		if (!d.sets.length) h += '<p>تکراری‌ای پیدا نشد.</p>';
		if (d.pages > 1) h += '<div class="utk-pg"><button class="button" data-dpg="' + (d.page - 1) + '"' + (d.page <= 1 ? ' disabled' : '') + '>قبلی</button> صفحه ' + d.page + ' از ' + d.pages + ' <button class="button" data-dpg="' + (d.page + 1) + '"' + (d.page >= d.pages ? ' disabled' : '') + '>بعدی</button></div>';
		P().innerHTML = h;
	}

	/* ------------------------------------------------------------- trash */
	async function vTrash() {
		P().innerHTML = '<p>در حال بارگذاری…</p>';
		let d;
		try { d = await api('trash_list'); } catch (e) { P().innerHTML = '<div class="notice notice-error"><p>' + E(e.message) + '</p></div>'; return; }
		const tot = d.batches.reduce((a, b) => a + b.bytes, 0);
		let h = '<div class="utk-sum">سطل بازیافت: <b>' + N(d.batches.length) + '</b> دسته — <b>' + B(tot) + '</b> (این فضا هنوز روی هاست مصرف می‌شود تا سطل را خالی کنید)</div>';
		h += '<table class="widefat striped"><thead><tr><th>زمان</th><th>کاربر</th><th>عملیات</th><th>موارد</th><th>فایل‌ها</th><th>حجم</th><th></th></tr></thead><tbody>' +
			d.batches.map((b) => '<tr><td>' + E(DT(b.time)) + '</td><td>' + E(b.user) + '</td><td>' + E((L.mode[b.mode] || b.mode) + (b.label ? ' — ' + b.label : '')) + '</td><td>' + N(b.items) + '</td><td>' + N(b.files) + '</td><td>' + B(b.bytes) + '</td><td><button class="button" data-act="t-restore" data-bid="' + E(b.id) + '">بازگردانی</button> <button class="button" data-act="t-purge" data-bid="' + E(b.id) + '">حذف دائمی</button></td></tr>').join('') +
			(d.batches.length ? '' : '<tr><td colspan="7">سطل خالی است.</td></tr>') + '</tbody></table>' +
			(d.batches.length ? '<p><button class="button" data-act="t-purge-all">خالی‌کردن کل سطل</button></p>' : '');
		P().innerHTML = h;
	}

	/* ---------------------------------------------------------- settings */
	async function vSettings() {
		P().innerHTML = '<p>در حال بارگذاری…</p>';
		let ov, lg;
		try { ov = await api('overview'); lg = await api('log'); } catch (e) { P().innerHTML = '<div class="notice notice-error"><p>' + E(e.message) + '</p></div>'; return; }
		const s = ov.settings;
		const num = (k, label, hint) => '<tr><th>' + E(label) + '</th><td><input type="number" min="0" data-set="' + k + '" value="' + E(s[k]) + '" style="width:100px"> <span class="description">' + E(hint) + '</span></td></tr>';
		let h = '<div class="utk-card"><h2>تنظیمات</h2><table class="form-table"><tbody>' +
			num('min_age', 'حداقل سن فایل (روز)', 'فایل‌های جدیدتر از این مقدار هرگز حذف نمی‌شوند (آپلودهای تازه‌ای که هنوز در محتوا ننشسته‌اند).') +
			num('trash_days', 'نگه‌داری سطل (روز)', 'بعد از این مدت، دسته‌های سطل خودکار برای همیشه حذف می‌شوند. ۰ = هرگز.') +
			num('max_age_h', 'حداکثر عمر نتیجه‌ی اسکن برای حذف (ساعت)', 'بعد از این مدت باید دوباره اسکن کنید.') +
			num('budget', 'زمان کار هر درخواست اسکن (ثانیه)', 'اگر هاست timeout می‌دهد کمتر کنید.') +
			'<tr><th>الگوریتم هش</th><td><select data-set="algo">' + U.algos.map((a) => '<option' + (a === s.algo ? ' selected' : '') + '>' + a + '</option>').join('') + '</select> <label><input type="checkbox" data-set="hash" ' + (s.hash ? 'checked' : '') + '> هش‌گیری در اسکن</label></td></tr>' +
			'<tr><th>ارجاع از پیش‌نویس/زباله‌دان</th><td><label><input type="checkbox" data-set="inactive_used" ' + (s.inactive_used ? 'checked' : '') + '> ارجاع از پیش‌نویس و زباله‌دان هم «استفاده» حساب شود (محافظه‌کارانه‌تر)</label></td></tr>' +
			'<tr><th>الگوهای محافظت‌شده</th><td><textarea data-set="protect" rows="4" cols="50" dir="ltr" placeholder="2019/*&#10;logos/*">' + E(s.protect) + '</textarea><br><span class="description">هر خط یک الگو (fnmatch) نسبت به پوشه‌ی uploads؛ فایل‌های منطبق هرگز حذف نمی‌شوند.</span></td></tr>' +
			'</tbody></table><p><button class="button button-primary" data-act="save-set">ذخیره</button> <span id="utk-saved"></span></p></div>';
		h += '<h2>گزارش عملیات</h2><table class="widefat striped"><thead><tr><th>زمان</th><th>کاربر</th><th>عملیات</th><th>موارد</th><th>فایل‌ها</th><th>حجم</th><th>توضیح</th></tr></thead><tbody>' +
			lg.log.map((r) => '<tr><td>' + E(DT(r.t)) + '</td><td>' + E(r.u) + '</td><td>' + E((L.mode[r.a] || r.a) + (r.mode ? ' (' + (L.mode[r.mode] || r.mode) + ')' : '')) + '</td><td>' + N(r.items) + '</td><td>' + N(r.files) + '</td><td>' + B(r.bytes) + '</td><td>' + E(r.label || r.batch || '') + '</td></tr>').join('') +
			(lg.log.length ? '' : '<tr><td colspan="7">هنوز عملیاتی ثبت نشده.</td></tr>') + '</tbody></table>';
		P().innerHTML = h;
	}

	/* ------------------------------------------------------------ events */
	let debounce = 0;
	root.addEventListener('input', (e) => {
		const t = e.target;
		if (t.dataset.f && (t.type === 'search' || t.type === 'text' || t.type === 'number')) {
			S.f[t.dataset.f] = t.value;
			clearTimeout(debounce);
			debounce = setTimeout(() => { S.page = 1; S.sel.clear(); S.selAll = false; loadList(); }, 400);
		}
	});
	root.addEventListener('change', (e) => {
		const t = e.target;
		if (t.dataset.f && !(t.type === 'search' || t.type === 'text' || t.type === 'number')) {
			S.f[t.dataset.f] = t.type === 'checkbox' ? (t.checked ? 1 : 0) : t.value;
			S.page = 1; S.sel.clear(); S.selAll = false; loadList();
		} else if (t.dataset.s) {
			S[t.dataset.s] = t.dataset.s === 'per' ? parseInt(t.value, 10) : t.value;
			S.page = 1; loadList();
		} else if (t.classList.contains('utk-sel')) {
			const tr = t.closest('tr'), gid = tr.dataset.gid, r = S.list.rows.find((x) => x.gid === gid);
			if (t.checked) S.sel.set(gid, { bytes: r.bytes, reclaim: r.reclaim }); else S.sel.delete(gid);
			syncHeadChecks(); renderSelBar();
		} else if (t.id === 'utk-all') {
			S.selAll = false;
			S.list.rows.forEach((r) => { if (t.checked) S.sel.set(r.gid, { bytes: r.bytes, reclaim: r.reclaim }); else S.sel.delete(r.gid); });
			renderRows(); renderSelBar();
		} else if (t.classList.contains('utk-gsel')) {
			S.list.rows.filter((r) => r.grp === t.dataset.g).forEach((r) => { if (t.checked) S.sel.set(r.gid, { bytes: r.bytes, reclaim: r.reclaim }); else S.sel.delete(r.gid); });
			renderRows(); renderSelBar();
		}
	});

	function dupSets(all) {
		const sets = [];
		root.querySelectorAll('.utk-set').forEach((el) => {
			const del = Array.from(el.querySelectorAll('.utk-dsel:checked')).map((c) => c.closest('tr').dataset.gid);
			if (del.length) sets.push({ h: el.dataset.h, del });
		});
		return sets;
	}

	root.addEventListener('click', async (e) => {
		const a = e.target.closest('[data-tab],[data-act],[data-go],[data-pg],[data-dpg],[data-more]');
		if (!a) return;
		if (a.tagName === 'A') e.preventDefault();
		if (a.dataset.tab) return show(a.dataset.tab);
		if (a.dataset.go) return show(a.dataset.go, JSON.parse(decodeURIComponent(a.dataset.flt || '{}')));
		if (a.dataset.pg) { S.page = parseInt(a.dataset.pg, 10); return loadList(); }
		if (a.dataset.dpg) { S.dupPage = parseInt(a.dataset.dpg, 10); return vDups(); }
		if (a.dataset.more) {
			const tr = a.closest('tr'), nx = tr.nextElementSibling;
			if (nx && nx.classList.contains('utk-detail')) { nx.remove(); return; }
			const d = await api('files', { gid: tr.dataset.gid });
			const det = document.createElement('tr');
			det.className = 'utk-detail';
			det.innerHTML = '<td></td><td colspan="7"><table class="widefat"><tbody>' + d.files.map((f) => '<tr class="fl-' + f.flag + '"><td><code dir="ltr">' + E(f.rel) + '</code></td><td>' + B(f.size) + '</td><td>' + E(L.flag[f.flag] || f.flag) + '</td><td>' + E(DT(f.mt)) + '</td></tr>').join('') + '</tbody></table></td>';
			tr.after(det);
			return;
		}
		switch (a.dataset.act) {
			case 'scan-start': return runScan(false);
			case 'scan-resume': return runScan(true);
			case 'scan-cancel': await api('scan_cancel'); return show('overview');
			case 'reset-f': S.f = defF(); S.page = 1; S.sel.clear(); S.selAll = false; return vExplorer();
			case 'csv': return window.open(U.csv + '&f=' + encodeURIComponent(JSON.stringify(S.f)), '_blank');
			case 'sel-all': S.selAll = true; renderRows(); return renderSelBar();
			case 'sel-none': S.sel.clear(); S.selAll = false; renderRows(); return renderSelBar();
			case 'op-group':
			case 'op-stale': {
				const mode = a.dataset.act === 'op-group' ? 'group' : 'stale';
				const base = { mode, perm: document.getElementById('utk-perm').checked, alt: document.getElementById('utk-alt').checked, label: 'Explorer' };
				return runOp(S.selAll ? Object.assign(base, { filter: S.f }) : Object.assign(base, { gids: selectionGids() }));
			}
			case 'quick-stale': return runOp({ mode: 'stale', filter: JSON.parse(decodeURIComponent(a.dataset.flt)), perm: false, alt: false, label: 'پاکسازی سریع: نسخه‌های اضافه' });
			case 'dups-page': {
				const sets = dupSets();
				if (!sets.length) return alert('چیزی انتخاب نشده.');
				return runOp({ mode: 'group', sets: true, gids: sets, perm: document.getElementById('utk-dperm').checked, label: 'تکراری‌ها' });
			}
			case 'dups-all': {
				const r = await api('dups_all');
				return runOp({ mode: 'group', sets: true, gids: r.sets, perm: document.getElementById('utk-dperm').checked, label: 'تکراری‌ها (همه)' });
			}
			case 't-restore': {
				if (!confirm('این دسته بازگردانی شود؟')) return;
				const r = await api('trash_restore', { bid: a.dataset.bid });
				alert(N(r.files) + ' فایل بازگردانی شد' + (r.conflicts ? ' (' + r.conflicts + ' فایل به‌خاطر وجود فایل هم‌نام رد شد)' : '') + '.\nبرای نتایج دقیق دوباره اسکن کنید.');
				return vTrash();
			}
			case 't-purge': {
				if (!confirm('این دسته برای همیشه حذف شود؟ (غیرقابل بازگشت)')) return;
				const r = await api('trash_purge', { bid: a.dataset.bid });
				alert(B(r.bytes) + ' آزاد شد.');
				return vTrash();
			}
			case 't-purge-all': {
				if (prompt('کل سطل برای همیشه حذف می‌شود. برای تأیید DELETE را تایپ کنید:') !== 'DELETE') return;
				const r = await api('trash_purge_all', { confirm: 'DELETE' });
				alert(B(r.bytes) + ' آزاد شد.');
				return vTrash();
			}
			case 'save-set': {
				const o = {};
				root.querySelectorAll('[data-set]').forEach((el) => { o[el.dataset.set] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value; });
				await api('settings_save', { settings: o });
				document.getElementById('utk-saved').textContent = 'ذخیره شد ✓';
				return;
			}
		}
	});

	show('overview');
})();
