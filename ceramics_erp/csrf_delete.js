// POST + CSRF দিয়ে ডিলিট: postDelete('page.php', {id: 5, csrf_token: '...'})
function postDelete(url, fields) {
    var f = document.createElement('form');
    f.method = 'POST'; f.action = url; f.style.display = 'none';
    fields = fields || {}; fields.do_delete = '1';
    Object.keys(fields).forEach(function (k) {
        var i = document.createElement('input');
        i.type = 'hidden'; i.name = k; i.value = fields[k];
        f.appendChild(i);
    });
    document.body.appendChild(f);
    f.submit();
}
