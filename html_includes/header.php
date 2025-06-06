	<!-- FlickSell Custom Elements -->
	<script>
		// Define flicksell-checkout custom element
		class FlickSellCheckout extends HTMLElement {
			constructor() {
				super();
				// Create shadow DOM
				this.attachShadow({ mode: 'open' });
			}

			connectedCallback() {
				// Check if user is logged in
				var userId = <?= isset($_SESSION['user']) ? $_SESSION['user']['id'] : 0 ?>;
				
				if (userId == 0) {
					// User not logged in, show message instead of loading
					this.shadowRoot.innerHTML = `
						<div class="flicksell-checkout-error">
							<p>Please log in to proceed with checkout.</p>
							<a href="/flicksell-sign-in" class="btn btn-primary">Sign In</a>
						</div>
					`;
					return;
				}
				
				// Clear any existing content and show loading state
				this.shadowRoot.innerHTML = '';
				this.showLoadingState();
				// Load checkout content when element is added to DOM
				this.loadCheckoutContent();
			}

			showLoadingState() {
				this.shadowRoot.innerHTML = `
                    <style>
                        .flicksell-checkout-loading {
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            padding: 2rem;
                            background: #f8f9fa;
                            border-radius: 8px;
                            margin: 1rem 0;
                        }
                        .flicksell-checkout-spinner {
                            width: 20px;
                            height: 20px;
                            border: 2px solid #e9ecef;
                            border-top: 2px solid #007bff;
                            border-radius: 50%;
                            animation: flicksell-checkout-spin 1s linear infinite;
                            margin-right: 0.5rem;
                        }
                        @keyframes flicksell-checkout-spin {
                            0% { transform: rotate(0deg); }
                            100% { transform: rotate(360deg); }
                        }
                        .flicksell-checkout-error {
                            padding: 1rem;
                            background: #f8d7da;
                            color: #721c24;
                            border: 1px solid #f5c6cb;
                            border-radius: 8px;
                            margin: 1rem 0;
                        }
                    </style>
                    <div class="flicksell-checkout-loading">
                        <div class="flicksell-checkout-spinner"></div>
                        <span>Loading checkout...</span>
                    </div>
                `;
			}

			async loadCheckoutContent() {
				try {
					// Get user ID
					var userId = <?= isset($_SESSION['user']) ? $_SESSION['user']['id'] : 0 ?>;

					// Prepare URL parameters - only send user ID
					const urlParams = new URLSearchParams();
					urlParams.append('user_id', userId);

					// Fetch HTML content instead of using iframe
					const response = await fetch(`/flicksell-provider-endpoints/checkout?${urlParams.toString()}`);
					
					if (!response.ok) {
						throw new Error(`HTTP error! status: ${response.status}`);
					}
					
					const htmlContent = await response.text();
					
					// Process the HTML content
					this.processAndInjectHTML(htmlContent);

				} catch (error) {
					console.error('Checkout loading error:', error);
					this.shadowRoot.innerHTML = `
						<style>
							.flicksell-checkout-error {
								padding: 1rem;
								background: #f8d7da;
								color: #721c24;
								border: 1px solid #f5c6cb;
								border-radius: 8px;
								margin: 1rem 0;
							}
						</style>
                        <div class="flicksell-checkout-error">
                            <strong>Checkout Error:</strong> ${error.message}
                        </div>
                    `;
				}
			}

			processAndInjectHTML(htmlContent) {
				// Create a temporary DOM parser
				const parser = new DOMParser();
				const doc = parser.parseFromString(htmlContent, 'text/html');
				
				// Extract head content
				const headContent = doc.head ? doc.head.innerHTML : '';
				
				// Extract body content
				const bodyContent = doc.body ? doc.body.innerHTML : htmlContent;
				
				// Find all script tags and extract their content
				const scriptElements = doc.querySelectorAll('script');
				const scriptContents = [];
				
				// Extract script innerHTML and remove script tags from the content
				scriptElements.forEach(script => {
					if (script.innerHTML.trim()) {
						scriptContents.push(script.innerHTML);
					}
					// Remove the script tag from its parent
					if (script.parentNode) {
						script.parentNode.removeChild(script);
					}
				});
				
				// Create the final content with head content at top, then body content
				let finalContent = '';
				
				// Add head content at the top (styles, meta tags, etc.)
				if (headContent) {
					finalContent += headContent;
				}
				
				// Add processed body content (with script tags removed)
				const processedBodyContent = doc.body ? doc.body.innerHTML : bodyContent;
				finalContent += processedBodyContent;
				
				// Clear shadow root and add the content
				this.shadowRoot.innerHTML = finalContent;
				
				// Add extracted scripts at the end
				scriptContents.forEach(scriptContent => {
					const scriptElement = document.createElement('script');
					scriptElement.innerHTML = scriptContent;
					this.shadowRoot.appendChild(scriptElement);
				});
			}

			// Method to refresh checkout content
			refresh() {
				this.shadowRoot.innerHTML = '';
				this.showLoadingState();
				this.loadCheckoutContent();
			}
		}

		// Register the custom element
		customElements.define('flicksell-checkout', FlickSellCheckout);
	</script>