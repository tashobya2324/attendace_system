(function () {
  "use strict";

  var viewDateInput = document.getElementById("viewDate");
  var filterDept = document.getElementById("filterDept"), filterStatus = document.getElementById("filterStatus");
  var currentDate = window.INITIAL_DATE || new Date().toISOString().slice(0, 10);

  function nowHHMM() {
    var d = new Date();
    return String(d.getHours()).padStart(2, "0") + ":" + String(d.getMinutes()).padStart(2, "0");
  }

  // ---------------- Check In / Check Out tabs ----------------
  var tabIn = document.getElementById("tabIn"), tabOut = document.getElementById("tabOut");
  var panelIn = document.getElementById("panelIn"), panelOut = document.getElementById("panelOut");

  function showPanel(which) {
    var isIn = which === "in";
    panelIn.classList.toggle("hidden", !isIn);
    panelOut.classList.toggle("hidden", isIn);
    tabIn.className = "flex-1 text-sm font-semibold py-2 rounded " + (isIn ? "bg-green-100 text-green-700" : "text-gray-500");
    tabOut.className = "flex-1 text-sm font-semibold py-2 rounded " + (!isIn ? "bg-red-100 text-red-700" : "text-gray-500");
  }
  tabIn.addEventListener("click", function () { showPanel("in"); });
  tabOut.addEventListener("click", function () { showPanel("out"); });
  showPanel("in");

  document.getElementById("fTimeIn").value = nowHHMM();
  document.getElementById("fTimeOut").value = nowHHMM();

  viewDateInput.addEventListener("change", function () {
    currentDate = viewDateInput.value;
    var url = new URL(window.location);
    url.searchParams.set("date", currentDate);
    window.history.replaceState({}, "", url);
    loadLedger();
    loadStaffStatus();
  });

  function loadStaffStatus() {
    fetch("api/staff_status.php?date=" + currentDate)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var inSelect = document.getElementById("fStaffIn");
        var outSelect = document.getElementById("fStaffOut");

        if (!data.need_checkin.length) {
          inSelect.innerHTML = '<option value="">All staff have checked in</option>';
        } else {
          inSelect.innerHTML = data.need_checkin.map(function (s) {
            return '<option value="' + s.id + '">' + s.full_name + ' — ' + s.dept + '</option>';
          }).join("");
        }

        if (!data.need_checkout.length) {
          outSelect.innerHTML = '<option value="">No staff pending check-out</option>';
        } else {
          outSelect.innerHTML = data.need_checkout.map(function (s) {
            return '<option value="' + s.id + '">' + s.full_name + ' — ' + s.dept + '</option>';
          }).join("");
        }
      });
  }

  var STATUS_BADGE = {
    present: "bg-green-100 text-green-700",
    late: "bg-amber-100 text-amber-700",
    absent: "bg-red-100 text-red-700",
    leave: "bg-purple-100 text-purple-700"
  };
  var STATUS_LABEL = { present: "Present", late: "Late", absent: "Absent", leave: "On leave" };

  function initials(name) {
    return name.split(" ").map(function (s) { return s[0]; }).join("").slice(0, 2).toUpperCase();
  }

  function renderStrip(data) {
    var strip = document.getElementById("dayStrip");
    var tiles = [
      ["Present", data.counts.present, "text-green-600"],
      ["Late", data.counts.late, "text-amber-600"],
      ["Absent", data.counts.absent, "text-red-600"],
      ["On leave", data.counts.leave, "text-purple-700"],
      ["Not yet logged", data.not_logged, "text-gray-500"]
    ];
    strip.innerHTML = tiles.map(function (t) {
      return '<div class="bg-white border border-gray-200 rounded-lg p-4">'
        + '<div class="text-2xl font-bold tabular-nums ' + t[2] + '">' + t[1] + '</div>'
        + '<div class="text-[11px] uppercase tracking-wide text-gray-400 font-semibold mt-1">' + t[0] + '</div></div>';
    }).join("");
  }

  function renderLedger(data) {
    var rows = data.rows.filter(function (r) {
      if (filterDept.value && r.dept !== filterDept.value) return false;
      if (filterStatus.value && r.status !== filterStatus.value) return false;
      return true;
    });
    var body = document.getElementById("ledgerBody");
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="8" class="text-center text-gray-400 py-8">No entries match these filters.</td></tr>';
      return;
    }
    body.innerHTML = rows.map(function (r) {
      return '<tr class="border-t border-gray-100 hover:bg-gray-50">'
        + '<td class="px-3 py-2"><div class="flex items-center gap-2">'
        + '<div class="w-7 h-7 rounded-full bg-gray-100 border border-gray-200 flex items-center justify-center text-[10px] font-bold">' + initials(r.full_name) + '</div>'
        + '<div><div class="font-medium">' + r.full_name + '</div><div class="text-[11px] text-gray-400">' + r.designation + ' · ' + r.staff_no + '</div></div></div></td>'
        + '<td class="px-3 py-2 text-gray-600">' + r.dept + '</td>'
        + '<td class="px-3 py-2"><span class="inline-flex items-center gap-1.5 text-[11px] font-bold px-2.5 py-1 rounded-full ' + STATUS_BADGE[r.status] + '">' + STATUS_LABEL[r.status] + '</span></td>'
        + '<td class="px-3 py-2 text-right tabular-nums">' + (r.check_in ? r.check_in.slice(0, 5) : "—") + '</td>'
        + '<td class="px-3 py-2 text-right tabular-nums">' + (r.check_out ? r.check_out.slice(0, 5) : "—") + '</td>'
        + '<td class="px-3 py-2 text-right tabular-nums">' + (r.hours != null ? r.hours : "—") + '</td>'
        + '<td class="px-3 py-2 text-gray-500">' + (r.remarks || "—") + '</td>'
        + '<td class="px-3 py-2 text-right"><button type="button" class="text-xs text-red-600 hover:underline deleteEntryBtn" data-id="' + r.id + '">Delete</button></td>'
        + '</tr>';
    }).join("");

    body.querySelectorAll(".deleteEntryBtn").forEach(function (btn) {
      btn.addEventListener("click", function () {
        if (!confirm("Delete this attendance record? This cannot be undone.")) return;
        var body2 = new URLSearchParams();
        body2.set("csrf", window.CSRF_TOKEN);
        body2.set("id", btn.getAttribute("data-id"));
        fetch("api/delete_entry.php", { method: "POST", body: body2 })
          .then(function (r) { return r.json(); })
          .then(function () { loadLedger(); loadStaffStatus(); });
      });
    });
  }

  function loadLedger() {
    document.getElementById("ledgerDateLabel").textContent = new Date(currentDate + "T00:00:00").toLocaleDateString(undefined, { weekday: "long", month: "long", day: "numeric" });
    fetch("api/ledger.php?date=" + currentDate)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        renderStrip(data);
        renderLedger(data);
      });
  }
  filterDept.addEventListener("change", loadLedger);
  filterStatus.addEventListener("change", loadLedger);

  var formMsg = document.getElementById("formMsg");
  function showMsg(text, ok) {
    formMsg.textContent = text;
    formMsg.className = "text-xs mt-2 " + (ok ? "text-green-600" : "text-red-600");
  }

  function submitEntry(action, staffSelectId, timeId, methodId, remarksId) {
    var staffSelect = document.getElementById(staffSelectId);
    var staffId = staffSelect.value;
    var time = document.getElementById(timeId).value;
    var method = document.getElementById(methodId).value;
    var remarksEl = document.getElementById(remarksId);
    var remarks = remarksEl.value.trim();

    if (!staffId || !time) {
      showMsg("Select a staff member and time.", false);
      return;
    }

    var body = new URLSearchParams();
    body.set("csrf", window.CSRF_TOKEN);
    body.set("staff_id", staffId);
    body.set("date", currentDate);
    body.set("action", action);
    body.set("time", time);
    body.set("method", method);
    body.set("remarks", remarks);

    fetch("api/record_entry.php", { method: "POST", body: body })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data.error) { showMsg(data.error, false); return; }
        var name = staffSelect.options[staffSelect.selectedIndex] ? staffSelect.options[staffSelect.selectedIndex].text.split(" — ")[0] : "Staff member";
        showMsg((action === "in" ? "Check-in" : "Check-out") + " recorded for " + name + ".", true);
        remarksEl.value = "";
        loadLedger();
        loadStaffStatus();
      });
  }

  document.getElementById("fSubmitIn").addEventListener("click", function () {
    submitEntry("in", "fStaffIn", "fTimeIn", "fMethodIn", "fRemarksIn");
  });
  document.getElementById("fSubmitOut").addEventListener("click", function () {
    submitEntry("out", "fStaffOut", "fTimeOut", "fMethodOut", "fRemarksOut");
  });

  loadLedger();
  loadStaffStatus();
})();
