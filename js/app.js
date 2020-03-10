"use strict";
//-----------------------------------------------
// Implementation
//-----------------------------------------------

// global variable used by all objects?
// storage for settings and ways to make API calls
var totalcms = new TotalCMS({
    passport: "topsecret",
    uri: "http://localhost:8000/api.php",
});

// Process Dynamics Forms
const dynamicsForms = document.querySelectorAll("form.dynamics-form");
for (const form of dynamicsForms) {
    const dynamics = new DynamicsAdmin();
    const totalform = new TotalForm(form);
    dynamics.processForm(totalform);
}

// Process Dynamics Grid
const dynamicsGrids = document.querySelectorAll(".dynamics-grid");
for (const grid of dynamicsGrids) {
    const dynamics = new Dynamics();
    dynamics.buildGrid(grid);
}
