(()=>{"use strict";function e(t){return e="function"==typeof Symbol&&"symbol"==typeof Symbol.iterator?function(e){return typeof e}:function(e){return e&&"function"==typeof Symbol&&e.constructor===Symbol&&e!==Symbol.prototype?"symbol":typeof e},e(t)}function t(e,t){for(var n=0;n<t.length;n++){var r=t[n];r.enumerable=r.enumerable||!1,r.configurable=!0,"value"in r&&(r.writable=!0),Object.defineProperty(e,o(r.key),r)}}function o(t){var o=function(t,o){if("object"!=e(t)||!t)return t;var n=t[Symbol.toPrimitive];if(void 0!==n){var r=n.call(t,o||"default");if("object"!=e(r))return r;throw new TypeError("@@toPrimitive must return a primitive value.")}return("string"===o?String:Number)(t)}(t,"string");return"symbol"==e(o)?o:o+""}function n(t,o,n){return o=i(o),function(t,o){if(o&&("object"==e(o)||"function"==typeof o))return o;if(void 0!==o)throw new TypeError("Derived constructors may only return object or undefined");return function(e){if(void 0===e)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return e}(t)}(t,r()?Reflect.construct(o,n||[],i(t).constructor):o.apply(t,n))}function r(){try{var e=!Boolean.prototype.valueOf.call(Reflect.construct(Boolean,[],(function(){})))}catch(e){}return(r=function(){return!!e})()}function i(e){return i=Object.setPrototypeOf?Object.getPrototypeOf.bind():function(e){return e.__proto__||Object.getPrototypeOf(e)},i(e)}function c(e,t){return c=Object.setPrototypeOf?Object.setPrototypeOf.bind():function(e,t){return e.__proto__=t,e},c(e,t)}var l=wp.compose.compose,u=wp.data,a=u.withSelect,s=u.dispatch,p=wp.element,f=p.Component,y=p.Fragment,m=window.PGC_SGB_POST,b=function(e){function o(){return function(e,t){if(!(e instanceof t))throw new TypeError("Cannot call a class as a function")}(this,o),n(this,o,arguments)}return function(e,t){if("function"!=typeof t&&null!==t)throw new TypeError("Super expression must either be null or a function");e.prototype=Object.create(t&&t.prototype,{constructor:{value:e,writable:!0,configurable:!0}}),Object.defineProperty(e,"prototype",{writable:!1}),t&&c(e,t)}(o,e),r=o,(i=[{key:"copyToClipboard",value:function(e){var __=wp.i18n.__;if(document.getElementsByClassName(e)[0]){window.getSelection().removeAllRanges();var t=document.createRange();t.selectNodeContents(document.getElementsByClassName(e)[0]),window.getSelection().addRange(t);try{document.execCommand("copy")?(console.log("Shortcode copied to clipboard."),s("core/notices").createNotice("success","".concat(__("Shortcode copied to clipboard.","simply-gallery-block")),{type:"snackbar",isDismissible:!0,id:"pgc-notic"})):s("core/notices").createNotice("warning","".concat(__("Cannot copy Shortcode.","simply-gallery-block")),{type:"snackbar",isDismissible:!0,id:"pgc-notic"})}catch(e){console.log(e)}window.getSelection().removeAllRanges()}}},{key:"return",value:function(){}},{key:"render",value:function(){var e=this,__=wp.i18n.__,t=this.props,o=t.postID,n=t.postSlug,r=t.blockCount,i=t.isCurrentPostPublished,c="[".concat(m.postType,' id="').concat(o,'"]'),l="[".concat(m.postType,' slug="').concat(n,'"]');return wp.element.createElement(y,null,wp.element.createElement("div",{className:"pgc-sgb-post-plug"},wp.element.createElement("div",{className:"pgc-description"},wp.element.createElement("div",{className:"pgc-title"},__("Welcome!","simply-gallery-block")),__("The Shortcode is designed to transfer (clone) this gallery to various places (posts, pages, sitebars, footers, etc.) on your site. Also, the shortcode can be used to add a gallery when using third-party Page Builders (which have a shortcode widget).","simply-gallery-block")),0===r&&wp.element.createElement("div",{className:"pgc-info-notic"},__('Please add a Gallery Block using the "Plus" button. And then publish this post!',"simply-gallery-block")),r>1&&wp.element.createElement("div",{className:"pgc-warning-notic"},__("For the Shortcode to work correctly, we strongly recommend that you publish only one Gallery in this post.","simply-gallery-block")),1===r&&i&&wp.element.createElement(y,null,wp.element.createElement("div",{className:"pgc-success-notic"},__("The shortcode for this gallery is ready:","simply-gallery-block")),wp.element.createElement("div",{className:"pgc-sgb-flex-column"},wp.element.createElement("code",{className:"pgc_sgb_shortcode_id",role:"button",tabIndex:"0",onKeyDown:null,onClick:function(){e.copyToClipboard("pgc_sgb_shortcode_id")}},c),wp.element.createElement("div",{className:"pgc-warning-notic"},__("Using a short code with Slug is a risk of losing the gallery when editing the Slug for SimpLy Gallery post!","simply-gallery-block")),wp.element.createElement("code",{className:"pgc_sgb_shortcode_slug",role:"button",tabIndex:"0",onKeyDown:null,onClick:function(){e.copyToClipboard("pgc_sgb_shortcode_slug")}},l)))))}}])&&t(r.prototype,i),l&&t(r,l),Object.defineProperty(r,"prototype",{writable:!1}),r;var r,i,l}(f);const d=l([a((function(e){return{postID:e("core/editor").getEditedPostAttribute("id"),postSlug:e("core/editor").getEditedPostAttribute("slug"),blockCount:e("core/block-editor").getBlockCount(),isCurrentPostPublished:e("core/editor").isCurrentPostPublished()}}))])(b);function g(e){return g="function"==typeof Symbol&&"symbol"==typeof Symbol.iterator?function(e){return typeof e}:function(e){return e&&"function"==typeof Symbol&&e.constructor===Symbol&&e!==Symbol.prototype?"symbol":typeof e},g(e)}function w(e,t){for(var o=0;o<t.length;o++){var n=t[o];n.enumerable=n.enumerable||!1,n.configurable=!0,"value"in n&&(n.writable=!0),Object.defineProperty(e,h(n.key),n)}}function h(e){var t=function(e,t){if("object"!=g(e)||!e)return e;var o=e[Symbol.toPrimitive];if(void 0!==o){var n=o.call(e,t||"default");if("object"!=g(n))return n;throw new TypeError("@@toPrimitive must return a primitive value.")}return("string"===t?String:Number)(e)}(e,"string");return"symbol"==g(t)?t:t+""}function v(e,t,o){return t=P(t),function(e,t){if(t&&("object"==g(t)||"function"==typeof t))return t;if(void 0!==t)throw new TypeError("Derived constructors may only return object or undefined");return function(e){if(void 0===e)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return e}(e)}(e,S()?Reflect.construct(t,o||[],P(e).constructor):t.apply(e,o))}function S(){try{var e=!Boolean.prototype.valueOf.call(Reflect.construct(Boolean,[],(function(){})))}catch(e){}return(S=function(){return!!e})()}function P(e){return P=Object.setPrototypeOf?Object.getPrototypeOf.bind():function(e){return e.__proto__||Object.getPrototypeOf(e)},P(e)}function O(e,t){return O=Object.setPrototypeOf?Object.setPrototypeOf.bind():function(e,t){return e.__proto__=t,e},O(e,t)}var _=wp.element,E=_.Component,j=_.Fragment,k=wp.editPost,C=k.PluginSidebar,T=k.PluginSidebarMoreMenuItem,N="pgc-sgb-plugin-post-editor",B=wp.element.createElement("svg",{enableBackground:"new 0 0 24 24",height:"24px",version:"1.1",viewBox:"0 0 24 24",width:"24px"},wp.element.createElement("g",null,wp.element.createElement("text",{fontWeight:"bold",fontStyle:"normal",xmlSpace:"preserve",textAnchor:"start",fontFamily:"Georgia, Times, 'Times New Roman', serif",fontSize:"18",id:"svg_11",y:"16",x:"0",strokeWidth:"0",stroke:"#fff",fill:"#0085ba"},"[/]")));const R=function(e){function t(){return function(e,t){if(!(e instanceof t))throw new TypeError("Cannot call a class as a function")}(this,t),v(this,t,arguments)}return function(e,t){if("function"!=typeof t&&null!==t)throw new TypeError("Super expression must either be null or a function");e.prototype=Object.create(t&&t.prototype,{constructor:{value:e,writable:!0,configurable:!0}}),Object.defineProperty(e,"prototype",{writable:!1}),t&&O(e,t)}(t,e),o=t,(n=[{key:"render",value:function(){var __=wp.i18n.__;return wp.element.createElement(j,null,wp.element.createElement(T,{target:N,icon:B},"SimpLy ".concat(__("Shortcode","simply-gallery-block"))),wp.element.createElement(C,{name:N,icon:B,title:"SimpLy ".concat(__("Shortcode","simply-gallery-block"))},wp.element.createElement(d,null)))}}])&&w(o.prototype,n),r&&w(o,r),Object.defineProperty(o,"prototype",{writable:!1}),o;var o,n,r}(E);function x(e){return x="function"==typeof Symbol&&"symbol"==typeof Symbol.iterator?function(e){return typeof e}:function(e){return e&&"function"==typeof Symbol&&e.constructor===Symbol&&e!==Symbol.prototype?"symbol":typeof e},x(e)}function D(e,t){for(var o=0;o<t.length;o++){var n=t[o];n.enumerable=n.enumerable||!1,n.configurable=!0,"value"in n&&(n.writable=!0),Object.defineProperty(e,A(n.key),n)}}function A(e){var t=function(e,t){if("object"!=x(e)||!e)return e;var o=e[Symbol.toPrimitive];if(void 0!==o){var n=o.call(e,t||"default");if("object"!=x(n))return n;throw new TypeError("@@toPrimitive must return a primitive value.")}return("string"===t?String:Number)(e)}(e,"string");return"symbol"==x(t)?t:t+""}function I(e,t,o){return t=F(t),function(e,t){if(t&&("object"==x(t)||"function"==typeof t))return t;if(void 0!==t)throw new TypeError("Derived constructors may only return object or undefined");return function(e){if(void 0===e)throw new ReferenceError("this hasn't been initialised - super() hasn't been called");return e}(e)}(e,G()?Reflect.construct(t,o||[],F(e).constructor):t.apply(e,o))}function G(){try{var e=!Boolean.prototype.valueOf.call(Reflect.construct(Boolean,[],(function(){})))}catch(e){}return(G=function(){return!!e})()}function F(e){return F=Object.setPrototypeOf?Object.getPrototypeOf.bind():function(e){return e.__proto__||Object.getPrototypeOf(e)},F(e)}function L(e,t){return L=Object.setPrototypeOf?Object.setPrototypeOf.bind():function(e,t){return e.__proto__=t,e},L(e,t)}const W=function(e){function t(){return function(e,t){if(!(e instanceof t))throw new TypeError("Cannot call a class as a function")}(this,t),I(this,t,arguments)}return function(e,t){if("function"!=typeof t&&null!==t)throw new TypeError("Super expression must either be null or a function");e.prototype=Object.create(t&&t.prototype,{constructor:{value:e,writable:!0,configurable:!0}}),Object.defineProperty(e,"prototype",{writable:!1}),t&&L(e,t)}(t,e),o=t,(n=[{key:"render",value:function(){return wp.element.createElement(d,null)}}])&&D(o.prototype,n),r&&D(o,r),Object.defineProperty(o,"prototype",{writable:!1}),o;var o,n,r}(wp.element.Component);var K=wp.element,M=K.createRoot,z=K.render;if(document.getElementById("simply-gallery-block-post-editor")){var U=document.getElementById("simply-gallery-block-post-editor");M?M(U).render(wp.element.createElement(W,null)):z(wp.element.createElement(W,null),U)}(0,wp.plugins.registerPlugin)("pgc-sgb-plugin-post-editor",{render:R})})();