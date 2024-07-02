//-----------------------------------------------
// Total CMS Deck Field
//-----------------------------------------------

// https://sortablejs.github.io/Sortable/

class Deck extends TotalField {

    constructor(container, options) {
        super(container, options);

        this.template  = this.container.getElementsByTagName("template").item(0);
        this.plus      = this.container.getElementsByClassName("plus").item(0);
        this.deck      = this.container.getElementsByClassName("thedeck").item(0);

        this.initDeck();
    }

    initDeck() {
        this.plus.addEventListener("click", event => this.newCard(), false);
        this.newCard();
    }

    insertAfter(newNode, referenceNode) {
        referenceNode.parentNode.insertBefore(newNode, referenceNode.nextSibling);
    }

    rebuildCounters() {
        const counters = Array.from(this.container.getElementsByClassName("counter")).reverse();
        let index = 1;
        for (const counter of counters) {
            counter.innerHTML = index++;
        }
    }

    initSmartFields(card) {
        this.initSVGBoxes(card);
        this.initRangeSliders(card);
        this.initDatePickers(card);
        this.sortCards();
    }

    initSVGBoxes(card) {
        const nodes = card.getElementsByClassName("svg-box");
        for (const node of nodes) {
            const svg = new SVGField(node, JSON.parse(node.dataset.settings||"{}"));
        }
    }

    initDatePickers(card) {
        const nodes = card.getElementsByClassName("date-box");
        for (const node of nodes) {
            const picker = new DatePicker(node, JSON.parse(node.dataset.settings||"{}"));
        }
    }

    initRangeSliders(card) {
        const nodes = card.getElementsByClassName("range-slider");
        for (const node of nodes) {
            const slider = new RangeSlider(node, JSON.parse(node.dataset.settings||"{}"));
        }
    }

    getValue() {
        const deckData = [];
        const cards = Array.from(this.deck.getElementsByClassName("card"));
        for (const card of cards) {
            const fields = Array.from(card.querySelectorAll("fieldset>input,fieldset>textarea,fieldset>select"));
            const cardData = {};
            for (const field of fields) {
                cardData[field.name] = field.value;
            }
            deckData.push(cardData);
        }
        return deckData;
    }

    sortCards() {
        Sortable.create(this.deck,{
            handle:".move-card",
            draggable:".card",
            animation: 500,
            onEnd: (event) => this.rebuildCounters()
        });
    }

    newCard(addDataCallback) {
        const clone = document.importNode(this.template.content, true);
        const card  = clone.querySelector(".card");
        const del   = clone.querySelector(".delete");

        del.addEventListener("click", (event) => {
            card.parentNode.removeChild(card);
            this.rebuildCounters();
        }, false);

        const fields = Array.from(clone.querySelectorAll("fieldset"));
        fields.forEach(field => field.classList.add("deck-field"));

        if (typeof addDataCallback === "function") {
            // Add existing card to the end
            addDataCallback(clone);
            this.deck.appendChild(clone);
        }
        else {
            // New cards go at the top
            this.insertAfter(clone,this.plus);
        }

        this.rebuildCounters();
        this.initSmartFields(card);
    }

    schema() {
        return {
            "type":"array",
            "fieldset":"deck"
        };
    }

    setValue(cardData) {
        if (typeof cardData === "object") {
            cardData.forEach(data => this.newCard(card => {
                // loop through all of the data fields and populate the card fields
                for (const name in data) {
                    //! TODO This would be nice if it worked on the fieldset level like TotalForm does
                    const input = card.querySelector(`input[name=${name}],textarea[name=${name}],select[name=${name}]`);
                    if (input) input.value = data[name];
                }
                // remove the new card class since its not new
                card.querySelector(".card").classList.remove("new");
            }));
            // Nuke any existing new cards
            this.deck.querySelectorAll(".card.new").forEach(card => card.parentNode.removeChild(card));
        }
    }

}
