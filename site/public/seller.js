(function () {
  "use strict";
  var table = document.querySelector("table.seller-listings");
  if (!table) return;
  var headers = Array.from(table.tHead.rows[0].cells);
  var column = -1;
  var direction = 1;
  headers.forEach(function (header, index) {
    var button = document.createElement("button");
    button.type = "button";
    button.className = "seller-sort";
    button.textContent = header.textContent;
    var arrow = document.createElement("span");
    arrow.className = "ar off";
    arrow.textContent = " \u25bc";
    button.appendChild(arrow);
    header.textContent = "";
    header.appendChild(button);
    header.setAttribute("aria-sort", "none");
    button.addEventListener("click", function () {
      direction = column === index ? -direction : (header.dataset.type === "text" ? 1 : -1);
      column = index;
      var rows = Array.from(table.tBodies[0].rows).filter(function (row) {
        return !row.querySelector("[colspan]");
      });
      rows.sort(function (a, b) {
        if (header.dataset.type === "text") {
          return direction * a.cells[index].textContent.trim().localeCompare(b.cells[index].textContent.trim(), undefined, { numeric: true });
        }
        var x = a.cells[index].dataset.sort;
        var y = b.cells[index].dataset.sort;
        if (x === "" || y === "") return x === y ? 0 : (x === "" ? 1 : -1);
        return direction * (Number(x) - Number(y));
      });
      rows.forEach(function (row) { table.tBodies[0].appendChild(row); });
      headers.forEach(function (other, otherIndex) {
        other.setAttribute("aria-sort", otherIndex === index ? (direction === 1 ? "ascending" : "descending") : "none");
        var marker = other.querySelector(".ar");
        marker.classList.toggle("off", otherIndex !== index);
        marker.textContent = direction === 1 ? " \u25b2" : " \u25bc";
      });
    });
  });
})();
