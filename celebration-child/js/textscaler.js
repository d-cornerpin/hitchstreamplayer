// Set this to true to enable console logs for debugging, false to disable them
var debugMode = false;

function debugLog(message) {
    if (debugMode) {
        console.log(message);
    }
}

$(function() { // This runs when the DOM is ready
    debugLog('DOM ready - applying text scaling.');
    applyTextScaling();
});

window.onload = function() { // This runs once everything is loaded
    debugLog('Window onload - reapplying text scaling.');
    applyTextScaling();
};

function applyTextScaling() {
    debugLog('Applying text scaling...');
    if (window.textScalingConfig && Array.isArray(window.textScalingConfig)) {
        window.textScalingConfig.forEach(function(config, index) {
            debugLog(`Scaling text for config ${index}: ${JSON.stringify(config)}`);
            textScale(config.parentClass, config.childClass, config.maxFontSize);
        });
    } else {
        console.error('Text scaling configuration is not properly defined.');
    }
}

// Define the textScale function outside of the jQuery ready function to ensure it's globally accessible
window.textScale = function (parentClassName, childClassName, maxFontSize) {
    const adjustFontSize = function() {
        debugLog(`Adjusting font size for ${childClassName} inside ${parentClassName}`);
        const parentDiv = $(parentClassName);
        const divWidth = parentDiv.width();
        debugLog(`Parent div width: ${divWidth}`);

        const targetText = $(childClassName);
        targetText.css('font-size', maxFontSize + 'px'); // Initial font size

        let fontSize = maxFontSize;
        const clone = targetText.clone().css({
            'display': 'inline-block',
            'position': 'fixed', // Use fixed to avoid affecting layout
            'visibility': 'hidden',
            'max-width': 'none',
            'white-space': 'nowrap', // Ensure single-line for accurate width measurement
            'font-size': fontSize + 'px'
        }).appendTo('body');

        debugLog(`Initial clone width: ${clone.width()} with font size: ${fontSize}`);

        // Match clone's CSS to target
        clone.css({
            'font-family': targetText.css('font-family'),
            'font-weight': targetText.css('font-weight'),
            'font-style': targetText.css('font-style'),
        });

        // Reduce font size until the text fits or reaches minimum allowed size
        while (clone.width() > divWidth && fontSize > 0) {
            fontSize -= 1;
            clone.css('font-size', fontSize + 'px');
            debugLog(`Adjusted clone width: ${clone.width()} with font size: ${fontSize}`);
        }

        // Apply calculated font size
        targetText.css('font-size', fontSize + 'px');
        debugLog(`Final font size applied to ${childClassName}: ${fontSize}`);

        clone.remove(); // Clean up
    };

    // Adjust font size immediately and on resize
    adjustFontSize();
    $(window).resize(function() {
        debugLog(`Window resize detected - reapplying text scaling for ${childClassName}`);
        adjustFontSize();
    });
};
