let timer;
const searchInput = document.getElementById('searchInput');

function applyFilters() {
    const url = new URL(window.location.href);
    const search = searchInput ? searchInput.value : '';
    const user = document.getElementById('userFilter') ? document.getElementById('userFilter').value : '';

    if (search) {
        url.searchParams.set('search', search);
    } else {
        url.searchParams.delete('search');
    }

    if (user) {
        url.searchParams.set('user', user);
    } else {
        url.searchParams.delete('user');
    }

    url.searchParams.delete('pg');
    window.location.href = url.toString();
}

if (searchInput) {
    searchInput.addEventListener('keyup', function () {
        clearTimeout(timer);
        timer = setTimeout(applyFilters, 400);
    });
}
