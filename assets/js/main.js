
(function() {
  "use strict";

  /**
   * Apply .scrolled class to the body as the page is scrolled down
   */
  function toggleScrolled() {
    const selectBody = document.querySelector('body');
    const selectHeader = document.querySelector('#header');
    if (!selectHeader.classList.contains('scroll-up-sticky') && !selectHeader.classList.contains('sticky-top') && !selectHeader.classList.contains('fixed-top')) return;
    window.scrollY > 100 ? selectBody.classList.add('scrolled') : selectBody.classList.remove('scrolled');
  }

  document.addEventListener('scroll', toggleScrolled);
  window.addEventListener('load', toggleScrolled);

  /**
   * Mobile nav toggle
   */
  const mobileNavToggleBtn = document.querySelector('.mobile-nav-toggle');

  function mobileNavToogle() {
    document.querySelector('body').classList.toggle('mobile-nav-active');
    mobileNavToggleBtn.classList.toggle('bi-list');
    mobileNavToggleBtn.classList.toggle('bi-x');
  }
  mobileNavToggleBtn.addEventListener('click', mobileNavToogle);

  /**
   * Hide mobile nav on same-page/hash links
   */
  document.querySelectorAll('#navmenu a').forEach(navmenu => {
    navmenu.addEventListener('click', () => {
      if (document.querySelector('.mobile-nav-active')) {
        mobileNavToogle();
      }
    });

  });

  /**
   * Toggle mobile nav dropdowns
   */
  document.querySelectorAll('.navmenu .toggle-dropdown').forEach(navmenu => {
    navmenu.addEventListener('click', function(e) {
      e.preventDefault();
      this.parentNode.classList.toggle('active');
      this.parentNode.nextElementSibling.classList.toggle('dropdown-active');
      e.stopImmediatePropagation();
    });
  });

  /**
   * Preloader
   */
  const preloader = document.querySelector('#preloader');
  if (preloader) {
    window.addEventListener('load', () => {
      preloader.remove();
    });
  }

  /**
   * Scroll top button
   */
  let scrollTop = document.querySelector('.scroll-top');

  function toggleScrollTop() {
    if (scrollTop) {
      window.scrollY > 100 ? scrollTop.classList.add('active') : scrollTop.classList.remove('active');
    }
  }
  scrollTop.addEventListener('click', (e) => {
    e.preventDefault();
    window.scrollTo({
      top: 0,
      behavior: 'smooth'
    });
  });

  window.addEventListener('load', toggleScrollTop);
  document.addEventListener('scroll', toggleScrollTop);

  /**
   * Animation on scroll function and init
   */
  function aosInit() {
    AOS.init({
      duration: 600,
      easing: 'ease-in-out',
      once: true,
      mirror: false
    });
  }
  window.addEventListener('load', aosInit);

  /**
   * Auto generate the carousel indicators
   */
  document.querySelectorAll('.carousel-indicators').forEach((carouselIndicator) => {
    carouselIndicator.closest('.carousel').querySelectorAll('.carousel-item').forEach((carouselItem, index) => {
      if (index === 0) {
        carouselIndicator.innerHTML += `<li data-bs-target="#${carouselIndicator.closest('.carousel').id}" data-bs-slide-to="${index}" class="active"></li>`;
      } else {
        carouselIndicator.innerHTML += `<li data-bs-target="#${carouselIndicator.closest('.carousel').id}" data-bs-slide-to="${index}"></li>`;
      }
    });
  });

  /**
   * Initiate glightbox
   */
  const glightbox = GLightbox({
    selector: '.glightbox'
  });

  /**
   * Initiate Pure Counter
   */
  new PureCounter();

  /**
   * Init isotope layout and filters
   */
  document.querySelectorAll('.isotope-layout').forEach(function(isotopeItem) {
    let layout = isotopeItem.getAttribute('data-layout') ?? 'masonry';
    let filter = isotopeItem.getAttribute('data-default-filter') ?? '*';
    let sort = isotopeItem.getAttribute('data-sort') ?? 'original-order';

    let initIsotope;
    imagesLoaded(isotopeItem.querySelector('.isotope-container'), function() {
      initIsotope = new Isotope(isotopeItem.querySelector('.isotope-container'), {
        itemSelector: '.isotope-item',
        layoutMode: layout,
        filter: filter,
        sortBy: sort
      });
    });

    isotopeItem.querySelectorAll('.isotope-filters li').forEach(function(filters) {
      filters.addEventListener('click', function() {
        isotopeItem.querySelector('.isotope-filters .filter-active').classList.remove('filter-active');
        this.classList.add('filter-active');
        initIsotope.arrange({
          filter: this.getAttribute('data-filter')
        });
        if (typeof aosInit === 'function') {
          aosInit();
        }
      }, false);
    });

  });

  /**
   * Init swiper sliders
   */
  function initSwiper() {
    document.querySelectorAll(".init-swiper").forEach(function(swiperElement) {
      let config = JSON.parse(
        swiperElement.querySelector(".swiper-config").innerHTML.trim()
      );

      if (swiperElement.classList.contains("swiper-tab")) {
        initSwiperWithCustomPagination(swiperElement, config);
      } else {
        new Swiper(swiperElement, config);
      }
    });
  }

  window.addEventListener("load", initSwiper);

  /**
   * Correct scrolling position upon page load for URLs containing hash links.
   */
  window.addEventListener('load', function(e) {
    if (window.location.hash) {
      if (document.querySelector(window.location.hash)) {
        setTimeout(() => {
          let section = document.querySelector(window.location.hash);
          let scrollMarginTop = getComputedStyle(section).scrollMarginTop;
          window.scrollTo({
            top: section.offsetTop - parseInt(scrollMarginTop),
            behavior: 'smooth'
          });
        }, 100);
      }
    }
  });

  /**
   * Navmenu Scrollspy
   */
  let navmenulinks = document.querySelectorAll('.navmenu a');

  function navmenuScrollspy() {
    navmenulinks.forEach(navmenulink => {
      if (!navmenulink.hash) return;
      let section = document.querySelector(navmenulink.hash);
      if (!section) return;
      let position = window.scrollY + 200;
      if (position >= section.offsetTop && position <= (section.offsetTop + section.offsetHeight)) {
        document.querySelectorAll('.navmenu a.active').forEach(link => link.classList.remove('active'));
        navmenulink.classList.add('active');
      } else {
        navmenulink.classList.remove('active');
      }
    })
  }
  window.addEventListener('load', navmenuScrollspy);
  document.addEventListener('scroll', navmenuScrollspy);

  /**
   * Story Form Submission Handler
   */
  const submitStoryBtn = document.getElementById('submitStory');
  const storyForm = document.getElementById('storyForm');
  const formLoader = document.getElementById('formLoader');
  const toastMessage = document.getElementById('toastMessage');

  if (submitStoryBtn && storyForm) {
    submitStoryBtn.addEventListener('click', function(e) {
      e.preventDefault();

      // Validate form
      if (!storyForm.checkValidity()) {
        storyForm.reportValidity();
        return;
      }

      // Collect form data
      const formData = {
        firstName: document.getElementById('firstName').value,
        lastName: document.getElementById('lastName').value,
        phone: document.getElementById('phone').value,
        address: document.getElementById('address').value,
        story: document.getElementById('story').value,
        timestamp: new Date().toISOString()
      };

      // Log to console
      console.log('Story Form Submission:', formData);

      // Hide form and show loader
      storyForm.classList.add('d-none');
      formLoader.classList.remove('d-none');
      submitStoryBtn.disabled = true;

      // Simulate API call with timeout
      setTimeout(function() {
        // Hide loader
        formLoader.classList.add('d-none');
        
        // Show success toast
        toastMessage.classList.remove('d-none');

        // Reset form after 2 seconds and close modal
        setTimeout(function() {
          storyForm.reset();
          storyForm.classList.remove('d-none');
          toastMessage.classList.add('d-none');
          submitStoryBtn.disabled = false;
          
          // Close modal
          const modal = bootstrap.Modal.getInstance(document.getElementById('storyModal'));
          if (modal) {
            modal.hide();
          }
        }, 2000);
      }, 3000); // 3 second loader
    });

    // Reset form when modal is closed
    document.getElementById('storyModal').addEventListener('hidden.bs.modal', function() {
      storyForm.reset();
      storyForm.classList.remove('d-none');
      formLoader.classList.add('d-none');
      toastMessage.classList.add('d-none');
      submitStoryBtn.disabled = false;
    });
  }

})();

/**
 * Scrambled Text Animation
 */
(function() {
  "use strict";

  const scrambledTexts = document.querySelectorAll('.scrambled-text');
  
  function scrambleText(element, originalText) {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
    let scrambledText = '';
    
    for (let i = 0; i < originalText.length; i++) {
      if (originalText[i] === ' ' || originalText[i] === '.' || originalText[i] === ',' || originalText[i] === '—' || originalText[i] === "'") {
        scrambledText += originalText[i];
      } else {
        scrambledText += chars[Math.floor(Math.random() * chars.length)];
      }
    }
    
    return scrambledText;
  }
  
  function wrapLetters(element, text) {
    element.innerHTML = text.split('').map(char => {
      if (char === ' ') return ' ';
      return `<span class="letter">${char}</span>`;
    }).join('');
  }
  
  function revealText(element, originalText) {
    wrapLetters(element, originalText);
    const letters = element.querySelectorAll('.letter');
    
    letters.forEach((letter, index) => {
      setTimeout(() => {
        letter.classList.add('revealed');
      }, index * 50);
    });
  }
  
  function initScrambledText() {
    scrambledTexts.forEach(textElement => {
      const originalText = textElement.dataset.text;
      
      // Initially show scrambled version
      wrapLetters(textElement, scrambleText(textElement, originalText));
      const letters = textElement.querySelectorAll('.letter');
      letters.forEach(letter => letter.classList.add('scrambled'));
      
      // Create intersection observer
      const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            // Stop scrambling animation
            const scrambledLetters = entry.target.querySelectorAll('.letter.scrambled');
            scrambledLetters.forEach(letter => letter.classList.remove('scrambled'));
            
            // Start reveal animation after a short delay
            setTimeout(() => {
              revealText(entry.target, originalText);
            }, 200);
            
            observer.unobserve(entry.target);
          }
        });
      }, {
        threshold: 0.5,
        rootMargin: '0px 0px -100px 0px'
      });
      
      observer.observe(textElement);
    });
  }
  
  // Initialize when DOM is loaded
  document.addEventListener('DOMContentLoaded', initScrambledText);
  
})();