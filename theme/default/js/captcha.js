(function(){
  function refresh(img){
    const url = new URL(img.src, location.href);
    url.searchParams.set('_CAPTCHA', '1'); // ensure image mode
    url.searchParams.set('regen', '1');    // tell PHP to generate a new code
    url.searchParams.set('t', Date.now().toString()); // cache-buster
    img.src = url.toString();
    const root = img.closest('[data-captcha]');
    const input = root && root.querySelector('input[type="text"]');
    if (input) input.value = '';
  }

  document.addEventListener('click', function(e){
    const btn = e.target.closest('.btn-refresh');
    if (btn){
      const img = btn.closest('[data-captcha]')?.querySelector('.captcha-img');
      if (img) refresh(img);
    }
    const img = e.target.closest('[data-captcha] .captcha-img');
    if (img) refresh(img);
  });
})();