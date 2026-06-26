document.addEventListener('DOMContentLoaded', function() {
  const config = window.BRZ || {};
  // Broaden selector to catch nested items in shortcode output
  const items = document.querySelectorAll('.rank-math-list-item, .rank-math-faq-item, .rank-math-list .rank-math-list-item, #rank-math-faq .rank-math-list-item, .woocommerce-Tabs-panel .rank-math-list-item');

  if (!items.length) return;

  items.forEach(item => {
    const question = item.querySelector('.rank-math-question');
    const answer = item.querySelector('.rank-math-answer');
    if (!question || !answer) return;

    // Accessibility Setup
    question.setAttribute('role', 'button');
    question.setAttribute('aria-expanded', 'false');
    question.setAttribute('tabindex', '0');
    
    // Ensure answer is hidden initially
    answer.style.maxHeight = '0';

    // Click Handler
    question.addEventListener('click', (e) => {
      e.preventDefault();
      toggleItem(item, question, answer);
    });

    // Keyboard Handler
    question.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        toggleItem(item, question, answer);
      }
    });
  });

  function toggleItem(item, question, answer) {
    const isOpen = item.classList.contains('brz-active');

    // Single Open Logic
    if (config.singleOpen && !isOpen) {
      items.forEach(otherItem => {
        if (otherItem !== item && otherItem.classList.contains('brz-active')) {
          closeItem(otherItem);
        }
      });
    }

    if (isOpen) {
      closeItem(item);
    } else {
      openItem(item, question, answer);
    }
  }

  function openItem(item, question, answer) {
    item.classList.add('brz-active');
    question.setAttribute('aria-expanded', 'true');
    
    if (config.animate) {
      // Calculate height dynamically
      answer.style.maxHeight = answer.scrollHeight + 'px';
      
      // Reset max-height after transition to allow content resizing if needed
      // But for accordion, fixed height is safer for transition back.
      // We can listen to transitionend if we want to set it to auto, 
      // but that complicates closing. Keeping it pixel-based is fine for text.
    } else {
      answer.style.maxHeight = 'none';
    }
  }

  function closeItem(item) {
    item.classList.remove('brz-active');
    const question = item.querySelector('.rank-math-question');
    const answer = item.querySelector('.rank-math-answer');
    
    if(question) question.setAttribute('aria-expanded', 'false');
    if(answer) answer.style.maxHeight = '0';
  }
});
