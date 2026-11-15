(function(){
  // footer year
  document.querySelectorAll('[data-year]').forEach(function(e){e.textContent=new Date().getFullYear();});

  // mobile menu
  var t=document.getElementById('menuToggle'),m=document.getElementById('mobileMenu');
  if(t&&m){
    t.addEventListener('click',function(){
      var open=m.classList.toggle('open');
      t.setAttribute('aria-expanded',open?'true':'false');
    });
    m.querySelectorAll('a').forEach(function(a){a.addEventListener('click',function(){m.classList.remove('open');t.setAttribute('aria-expanded','false');});});
    window.addEventListener('resize',function(){if(window.innerWidth>=1024){m.classList.remove('open');t.setAttribute('aria-expanded','false');}});
  }

  // FAQ accordion (one open at a time)
  var items=document.querySelectorAll('.faq-item');
  items.forEach(function(item){
    var b=item.querySelector('.faq-q');
    b.addEventListener('click',function(){
      var wasOpen=item.classList.contains('open');
      items.forEach(function(i){i.classList.remove('open');i.querySelector('.faq-q').setAttribute('aria-expanded','false');});
      if(!wasOpen){item.classList.add('open');b.setAttribute('aria-expanded','true');}
    });
  });

  // Contact form: static site has no backend, so open the visitor's email app
  // addressed to the group inbox. Swap this for a form service (Formspree etc.) later.
  var f=document.getElementById('contactForm');
  if(f){
    var note=document.getElementById('formNote');
    f.addEventListener('submit',function(e){
      e.preventDefault();
      var d=new FormData(f),first=(d.get('first')||'').trim(),last=(d.get('last')||'').trim(),
          email=(d.get('email')||'').trim(),msg=(d.get('message')||'').trim();
      if(!first||!last||!email||!msg||!/^\S+@\S+\.\S+$/.test(email)){note.textContent='Please complete all fields with a valid email address.';return;}
      var body='Name: '+first+' '+last+'\nEmail: '+email+'\n\n'+msg;
      window.location.href='mailto:inquiries@credencegroup.com?subject='+encodeURIComponent('Website inquiry from '+first+' '+last)+'&body='+encodeURIComponent(body);
      note.textContent='Opening your email app to send the message. If nothing opens, email us at inquiries@credencegroup.com.';
    });
  }
})();
