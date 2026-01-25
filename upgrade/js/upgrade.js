/**
 * UPGRADE PAGE - JavaScript
 */

// Import particle system
document.write('<script src="/home/js/home.js"><\/script>');

// FAQ Accordion
document.addEventListener('DOMContentLoaded', () => {
    console.log('✅ Upgrade page loaded');
    
    // FAQ accordion
    document.querySelectorAll('.faq-item').forEach(item => {
        const question = item.querySelector('.faq-question');
        question.addEventListener('click', () => {
            // Close other items
            document.querySelectorAll('.faq-item').forEach(otherItem => {
                if (otherItem !== item) {
                    otherItem.classList.remove('active');
                }
            });
            
            // Toggle current item
            item.classList.toggle('active');
        });
    });
});