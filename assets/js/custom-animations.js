/* --- Scoped & Final Polished Scroll Reveal Text Animation Logic --- */
document.addEventListener('DOMContentLoaded', () => {
    const sectionWrapper = document.getElementById('scroll-reveal-section-wrapper');
    if (!sectionWrapper) {
        return;
    }

    const textWrapper = sectionWrapper.querySelector('.text-wrapper');
    const words = textWrapper.querySelectorAll('span');
    const parentContainer = sectionWrapper.querySelector('.animation-parent');

    if (!textWrapper || words.length === 0 || !parentContainer) {
        return;
    }

    const initialTransforms = [
        [-20, -20, 0], [0, -28, 0], [25, -22, 0], [40, -15, 0], [50, 0, 0],
        [-45, 10, 0], [-25, 20, 0], [0, 15, 0], [28, 25, 0], [-30, 45, 0],
        [-10, 58, 0], [15, 50, 0], [35, 60, 0], [50, 45, 0]
    ];

    const lerp = (start, end, t) => start * (1 - t) + end * t;
    const initialOpacity = 0.05;
    const easeInOutQuad = (t) => t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2;

    function animateText() {
        const animationStartPoint = parentContainer.offsetTop - window.innerHeight;
        const animationEndPoint = parentContainer.offsetTop + parentContainer.offsetHeight - window.innerHeight;

        const scrollTop = window.scrollY;
        let globalProgress = (scrollTop - animationStartPoint) / (animationEndPoint - animationStartPoint);
        globalProgress = Math.max(0, Math.min(1, globalProgress));

        if (isNaN(globalProgress)) { return; }

        const easedGlobalProgress = easeInOutQuad(globalProgress);

        words.forEach((word, index) => {
            // Movement animation
            const [x, y, rot] = initialTransforms[index] || [0, 0, 0];
            const currentX = lerp(x, 0, easedGlobalProgress);
            const currentY = lerp(y, 0, easedGlobalProgress);
            word.style.transform = `translate(${currentX}vw, ${currentY}vh) rotate(${rot}deg)`;

            // --- CORRECTED: Opacity animation timing ---
            const opacityDelay = 0.15; 
            // Reverted to the wider stagger range
            const staggerRange = 0.5; 
            const wordAnimationStart = opacityDelay + (index / words.length) * staggerRange;
            // Kept the longer duration for a slower individual fade
            const wordAnimationDuration = 0.6; 
            
            let wordProgress = (globalProgress - wordAnimationStart) / wordAnimationDuration;
            wordProgress = Math.max(0, Math.min(1, wordProgress));
            
            const easedOpacityProgress = easeInOutQuad(wordProgress);
            const currentOpacity = lerp(initialOpacity, 1, easedOpacityProgress);
            word.style.opacity = currentOpacity;
        });

        // Spacing animations
        const currentLetterSpacing = lerp(2, 1.5, easedGlobalProgress);
        textWrapper.style.letterSpacing = `${currentLetterSpacing}px`;
        const currentWordSpacing = lerp(0, 10, easedGlobalProgress);
        textWrapper.style.wordSpacing = `${currentWordSpacing}px`;
    }

    function setInitialState() {
        words.forEach((word, index) => {
            const [x, y, rot] = initialTransforms[index] || [0, 0, 0];
            word.style.transform = `translate(${x}vw, ${y}vh) rotate(${rot}deg)`;
            word.style.opacity = initialOpacity;
        });
        textWrapper.style.letterSpacing = '2px';
        textWrapper.style.wordSpacing = '0px';
    }
    
    setInitialState();
    window.addEventListener('scroll', animateText);
    
    setTimeout(animateText, 100);
});