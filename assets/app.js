document.addEventListener("DOMContentLoaded", function () {
  initializeBalances();
  initializeEventListeners();
  feather.replace();
});

function initializeBalances() {
  const balanceButtons = document.querySelectorAll(
    ".refresh-balance[data-account]",
  );

  // Use Promise.all to handle multiple initializations concurrently
  return Promise.all(
    Array.from(balanceButtons).map((button) =>
      refreshBalance(button.dataset.account),
    ),
  );
}

function initializeEventListeners() {
  // Transfer form
  document
    .getElementById("transferForm")
    .addEventListener("submit", handleTransferSubmit);

  // Transaction view buttons
  document.querySelectorAll(".view-transactions").forEach((button) => {
    button.addEventListener("click", () =>
      showTransactions(button.dataset.account),
    );
  });

  // Balance refresh buttons
  document.querySelectorAll(".refresh-balance").forEach((button) => {
    button.addEventListener("click", () =>
      refreshBalance(button.dataset.account),
    );
  });

  // From/To account selection
  const accountSelects = document.querySelectorAll(
    '[name="fromAccount"], [name="toAccount"]',
  );
  accountSelects.forEach((select) =>
    select.addEventListener("change", handleAccountSelection),
  );

  // Alert close button
  const alertCloseBtn = document.querySelector(".alert button");
  if (alertCloseBtn) {
    alertCloseBtn.addEventListener("click", () => {
      document.querySelector(".alert").classList.add("hidden");
    });
  }
}

async function handleTransferSubmit(e) {
  e.preventDefault();
  const form = e.target;
  const submitButton = form.querySelector('button[type="submit"]');

  try {
    const data = await fetchWithLock(
      window.location.href,
      {
        method: "POST",
        body: new FormData(form),
      },
      submitButton,
    );

    if (data.success) {
      showAlert("Transfer completed successfully");
      form.reset();

      // Reload ALL balances instead
      await initializeBalances();
    } else {
      showAlert(data.error || "Transfer failed", "error");
    }
  } catch (error) {
    console.error("Error:", error);
    showAlert("An error occurred processing the transfer", "error");
  }
}

function handleAccountSelection(e) {
  const fromSelect = document.querySelector('[name="fromAccount"]');
  const toSelect = document.querySelector('[name="toAccount"]');

  if (fromSelect.value === toSelect.value) {
    const otherSelect = e.target === fromSelect ? toSelect : fromSelect;
    const availableOption = Array.from(otherSelect.options).find(
      (option) => option.value !== e.target.value && !option.disabled,
    );

    if (availableOption) {
      otherSelect.value = availableOption.value;
    }
  }
}

// Queue for balance requests to prevent race conditions
const balanceRequestQueue = [];
let isProcessingQueue = false;

async function processBalanceQueue() {
  if (isProcessingQueue || balanceRequestQueue.length === 0) return;

  isProcessingQueue = true;

  while (balanceRequestQueue.length > 0) {
    const request = balanceRequestQueue.shift();
    await refreshBalanceInternal(request.accountId);
  }

  isProcessingQueue = false;
}

async function refreshBalanceInternal(accountId) {
  const button = document.querySelector(
    `.refresh-balance[data-account="${accountId}"]`,
  );
  if (!button) {
    console.warn("No element found for data-account=", accountId);
    return;
  }
  const container = button.closest(".account-card");
  const balanceInfo = container.querySelector(".balance-info");
  const spinner = container.querySelector(".loading-spinner");

  try {
    const data = await fetchWithLock(
      window.location.href,
      {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: new URLSearchParams({
          action: "getBalance",
          account: accountId,
        }),
      },
      button,
    );

    if (!data.success) {
      throw new Error(data.error || "Failed to fetch balance");
    }

    // Simple string HTML approach for balance info
    balanceInfo.innerHTML =
      '<div class="space-y-2">' +
      '<div class="text-sm text-gray-900">' +
      '<span class="font-medium">Balance: </span>' +
      '<span class="text-' +
      (Number(data.balance) >= 0 ? "green" : "red") +
      '-600">' +
      formatAmount(data.balance) +
      " " +
      data.currency +
      "</span></div>" +
      '<div class="text-sm text-gray-900">' +
      '<span class="font-medium">Available: </span>' +
      '<span class="text-' +
      (Number(data.available) >= 0 ? "green" : "red") +
      '-600">' +
      formatAmount(data.available) +
      " " +
      data.currency +
      "</span></div></div>";
  } catch (error) {
    console.error("Error:", error);
    balanceInfo.innerHTML =
      '<div class="text-red-600 flex items-center">' +
      '<i data-feather="alert-triangle" class="h-4 w-4 mr-2"></i>' +
      "Failed to load balance: " +
      (error.message || "Unknown error") +
      "</div>";
    feather.replace();
  }
}

// Function to refresh account balance
async function refreshBalance(accountId) {
  // Add the request to the queue
  balanceRequestQueue.push({ accountId });

  // Start processing the queue
  await processBalanceQueue();
}

function updateBalanceDisplay(element, data) {
  // Create balance elements
  const balanceContainer = document.createElement("div");
  balanceContainer.className = "space-y-2";

  const balanceRow = createBalanceRow("Balance", data.balance, data.currency);
  const availableRow = createBalanceRow(
    "Available",
    data.available,
    data.currency,
  );

  balanceContainer.appendChild(balanceRow);
  balanceContainer.appendChild(availableRow);

  // Clear and update the element
  element.innerHTML = "";
  element.appendChild(balanceContainer);
}

function createBalanceRow(label, amount, currency) {
  const row = document.createElement("div");
  row.className = "text-sm text-gray-900";

  const labelSpan = document.createElement("span");
  labelSpan.className = "font-medium";
  labelSpan.textContent = label + ": ";

  const amountSpan = document.createElement("span");
  amountSpan.className = `text-${amount >= 0 ? "green" : "red"}-600`;
  amountSpan.textContent = formatAmount(amount) + " " + currency;

  row.appendChild(labelSpan);
  row.appendChild(amountSpan);

  return row;
}

function showBalanceError(element, message) {
  const errorDiv = document.createElement("div");
  errorDiv.className = "text-red-600 flex items-center";

  const icon = document.createElement("i");
  icon.setAttribute("data-feather", "alert-triangle");
  icon.className = "h-4 w-4 mr-2";

  errorDiv.appendChild(icon);
  errorDiv.appendChild(
    document.createTextNode(message || "Error loading balance"),
  );

  element.innerHTML = "";
  element.appendChild(errorDiv);

  feather.replace();
}

function showTransactions(accountId) {
  const panel = document.getElementById("transactionsPanel");
  panel.dataset.account = accountId;
  panel.classList.remove("opacity-0", "pointer-events-none");
  refreshTransactions();
}

function hideTransactions() {
  const panel = document.getElementById("transactionsPanel");
  panel.classList.add("opacity-0", "pointer-events-none");
}

async function refreshTransactions() {
  const panel = document.getElementById("transactionsPanel");
  const accountId = panel.dataset.account;
  const list = document.getElementById("transactionsList");
  const spinner = panel.querySelector(".loading-spinner");
  const refreshButton = document.querySelector(
    'button[onclick="refreshTransactions()"]',
  );

  // Show spinner
  spinner.classList.remove("hidden");
  panel.classList.add("opacity-50");

  try {
    // Get date values from the input fields
    const startDate = document.getElementById("startDate").value;
    const endDate = document.getElementById("endDate").value;

    console.log(`Fetching transactions with dates: ${startDate} to ${endDate}`);

    const data = await fetchWithLock(
      window.location.href,
      {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: new URLSearchParams({
          action: "getTransactions",
          account: accountId,
          startDate: startDate,
          endDate: endDate,
        }),
      },
      refreshButton,
    );

    if (!data.success) {
      throw new Error(data.error || "Failed to load transactions");
    }

    console.log(
      `Received ${data.transactions ? data.transactions.length : 0} transactions`,
    );

    // Directly update the DOM with transaction data
    updateTransactionsList(list, data.transactions);
  } catch (error) {
    console.error("Error:", error);
    showAlert(error.message || "Failed to load transactions", "error");
  } finally {
    // Hide spinner
    spinner.classList.add("hidden");
    panel.classList.remove("opacity-50");
  }
}

function updateTransactionsList(element, transactions) {
  element.innerHTML = "";

  if (!transactions || transactions.length === 0) {
    const row = document.createElement("tr");
    const cell = document.createElement("td");
    cell.colSpan = 6;
    cell.className = "px-6 py-4 text-center text-sm text-gray-500";
    cell.textContent = "No transactions found";
    row.appendChild(cell);
    element.appendChild(row);
    return;
  }

  transactions.forEach((tx) => {
    const row = createTransactionRow(tx);
    element.appendChild(row);
  });
}

function createTransactionRow(tx) {
  const row = document.createElement("tr");
  row.className = "hover:bg-gray-50";

  const cells = [
    { text: formatDate(tx.bookingDate), class: "text-gray-900" },
    { text: tx.type, class: "text-gray-500" },
    { text: tx.description || "", class: "text-gray-900" },
    { text: tx.reference || "", class: "text-gray-500" },
    {
      text: `${formatAmount(tx.amount)} ${tx.currency}`,
      class: `text-${tx.amount >= 0 ? "green" : "red"}-600`,
    },
    {
      text: `${formatAmount(tx.balance)} ${tx.currency}`,
      class: "text-gray-900",
    },
  ];

  cells.forEach((cell) => {
    const td = document.createElement("td");
    td.className = `px-6 py-4 whitespace-nowrap text-sm ${cell.class}`;
    td.textContent = cell.text;
    row.appendChild(td);
  });

  return row;
}

function showAlert(message, type = "success") {
  const alert = document.querySelector(".alert");
  const messageEl = alert.querySelector(".alert-message");
  const icon = alert.querySelector(".alert-icon");
  const alertBox = alert.querySelector(".p-4");

  // Set message text
  messageEl.textContent = message;

  // Clear any previous styles
  alert.classList.remove("ring-red-500", "ring-green-500");
  alert.classList.add("ring-1");
  alertBox.classList.remove("bg-red-50", "bg-green-50");
  icon.classList.remove("text-red-500", "text-green-500");

  if (type === "error") {
    // Apply error styles
    alert.classList.add("ring-red-500");
    alertBox.classList.add("bg-red-50");
    icon.classList.add("text-red-500");

    // Set error icon (exclamation mark)
    icon.innerHTML =
      '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />';
  } else {
    // Apply success styles
    alert.classList.add("ring-green-500");
    alertBox.classList.add("bg-green-50");
    icon.classList.add("text-green-500");

    // Set success icon (checkmark)
    icon.innerHTML =
      '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />';
  }

  // Show the alert
  alert.classList.remove("hidden");

  // Hide after 5 seconds
  setTimeout(() => alert.classList.add("hidden"), 5000);
}

async function checkConnectivity() {
  const statusIndicator = document.querySelector(".status-indicator");
  const refreshButton = statusIndicator.querySelector("button");

  try {
    const data = await fetchWithLock(
      window.location.href,
      {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: new URLSearchParams({
          action: "checkConnectivity",
        }),
      },
      refreshButton,
    );

    updateConnectivityStatus(statusIndicator, data.success, data.error);
  } catch (error) {
    console.error("Error:", error);
    updateConnectivityStatus(statusIndicator, false, "Connection failed");
  }
}

function updateConnectivityStatus(element, isConnected, error = null) {
  const status = document.createElement("span");
  status.className = `inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
    isConnected ? "bg-green-100 text-green-800" : "bg-red-100 text-red-800"
  }`;
  status.textContent = isConnected ? "API Connected" : "API Error";

  element.innerHTML = "";
  element.appendChild(status);

  if (!isConnected && error) {
    const errorMsg = document.createElement("small");
    errorMsg.className = "block mt-1 text-xs text-red-600";
    errorMsg.textContent = error;
    element.appendChild(errorMsg);
  }
}

function formatDate(dateStr) {
  if (!dateStr) return "";
  const date = new Date(dateStr);
  return date.toLocaleString("et-EE", {
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
  });
}

// Helper function to format amounts
function formatAmount(amount) {
  return (Number(amount) || 0).toFixed(2);
}

function escapeHtml(unsafe) {
  const div = document.createElement("div");
  div.textContent = unsafe;
  return div.innerHTML;
}

// Wrap fetch to automatically handle request locking
async function fetchWithLock(url, options = {}, lockElement = null) {
  try {
    // Start the lock
    APIRequestLock.start(lockElement);

    // Perform the fetch
    const response = await fetch(url, options);

    // Parse the response
    const data = await response.json();

    return data;
  } catch (error) {
    // Rethrow or handle the error as needed
    console.error("Fetch error:", error);
    throw error;
  } finally {
    // Always end the lock
    APIRequestLock.end(lockElement);
  }
}

// Global API request lock mechanism
const APIRequestLock = {
  _activeRequests: 0,
  _lockedElements: new Set(),

  start(element = null) {
    this._activeRequests++;

    // Lock UI elements if an element is provided
    if (element) {
      this._lockElement(element);
    }

    // Globally lock all interactive elements
    if (this._activeRequests > 0) {
      this._lockGlobalInteractiveElements();
    }
  },

  end(element = null) {
    this._activeRequests = Math.max(0, this._activeRequests - 1);

    // Unlock specific element if provided
    if (element) {
      this._unlockElement(element);
    }

    // Unlock global elements if no active requests
    if (this._activeRequests === 0) {
      this._unlockGlobalInteractiveElements();
    }
  },

  _lockElement(element) {
    if (!element) return;

    // Disable the element and store its original state
    element.dataset.originalDisabled = element.disabled ? "true" : "false";
    element.disabled = true;

    // Add visual indication of loading
    if (element.classList) {
      element.classList.add("opacity-50", "cursor-not-allowed");
    }

    this._lockedElements.add(element);
  },

  _unlockElement(element) {
    if (!element) return;

    // Restore original disabled state
    element.disabled = element.dataset.originalDisabled === "true";

    // Remove visual loading indication
    if (element.classList) {
      element.classList.remove("opacity-50", "cursor-not-allowed");
    }

    delete element.dataset.originalDisabled;
    this._lockedElements.delete(element);
  },

  _lockGlobalInteractiveElements() {
    const interactiveElements = document.querySelectorAll(
      "button:not([disabled]), " +
        "input:not([disabled]), " +
        "select:not([disabled]), " +
        "textarea:not([disabled]), " +
        "a[href]:not([disabled])",
    );

    interactiveElements.forEach((el) => {
      if (!this._lockedElements.has(el)) {
        this._lockElement(el);
      }
    });
  },

  _unlockGlobalInteractiveElements() {
    this._lockedElements.forEach((el) => {
      this._unlockElement(el);
    });
    this._lockedElements.clear();
  },
};
