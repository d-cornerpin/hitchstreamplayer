document.addEventListener('DOMContentLoaded', function () {
    var eventStatus = document.getElementById('EventStatus');
    
    const updateEndedMessage = () => {
        document.getElementById('hs_player').style.display = 'none';
//        console.log('Changing to ended message');
        document.getElementById('hs_aftertext').textContent = endedMessage;

        // Fetch and apply the correct textScale settings from textScalingConfig
        const afterTextConfig = textScalingConfig.find(config => config.childClass === '.hs_aftertext');
        if (afterTextConfig) {
//            console.log('Scaling ended text');
            textScale(afterTextConfig.parentClass, afterTextConfig.childClass, afterTextConfig.maxFontSize);
        } else {
            console.error('.hs_aftertext configuration not found in textScalingConfig.');
        }
    };

    if (eventStatus && eventStatus.textContent.trim() === 'complete') {
        updateEndedMessage();
    }

    const targetNode = document.getElementById("EventStatus");
    if (targetNode) {
        const config = {
            childList: true,
            subtree: true,
            characterData: true
        };
        const callback = function (mutationsList) {
            for (let mutation of mutationsList) {
                if (mutation.type === 'childList' || mutation.type === 'characterData') {
                    if (targetNode.textContent.trim() === 'complete') {
                        updateEndedMessage();
                    }
                }
            }
        };
        const observer = new MutationObserver(callback);
        observer.observe(targetNode, config);
    } else {
        console.error('The target node #EventStatus was not found in the DOM.');
    }
}); 
