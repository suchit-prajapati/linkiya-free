import './sidebar.css';
import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/edit-post';
import { useSelect, useDispatch } from '@wordpress/data';
import { useState, useEffect, useRef } from '@wordpress/element';
import {
    Button, CheckboxControl, Spinner, Notice, PanelBody, PanelRow, TextControl,
} from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

const ICON = (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
        <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
        <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
    </svg>
);

const STATUS = { IDLE:'idle', LOADING:'loading', DONE:'done', APPLYING:'applying', APPLIED:'applied', ERROR:'error' };

const adminUrl = ( page ) => {
    const base = linkiyaData.adminUrl || '/wp-admin/';
    return base + 'admin.php?page=' + page;
};

// Unique key per suggestion — keyword suggestions use keyword, AI suggestions use "ai:{post_id}"
const suggKey = ( s ) => s.source === 'ai' ? `ai:${ s.post_id }` : s.keyword;

function SmartInternalLinkerSidebar() {
    const { postId, currentContent } = useSelect( ( select ) => ( {
        postId:         select( 'core/editor' ).getCurrentPostId(),
        currentContent: select( 'core/editor' ).getEditedPostAttribute( 'content' ),
    } ) );
    const { editPost }      = useDispatch( 'core/editor' );
    const { resetBlocks }   = useDispatch( 'core/block-editor' );
    const appliedContentRef = useRef( null );

    const isPro              = linkiyaData.isPro;
    const aiEnabled          = !! linkiyaData.ai_suggestions_enabled;
    const aiNonce            = linkiyaData.ai_suggest_nonce || '';
    const aiUrl              = linkiyaData.ai_suggest_url  || '';

    const [status,        setStatus]        = useState( STATUS.IDLE );
    const [suggestions,   setSuggestions]   = useState( [] );
    const [checked,       setChecked]       = useState( {} );
    const [anchorTexts,   setAnchorTexts]   = useState( {} );
    const [editingAnchor, setEditingAnchor] = useState( {} );
    const [errorMsg,      setErrorMsg]      = useState( '' );
    const [appliedCount,  setAppliedCount]  = useState( 0 );
    const [orphanCount,   setOrphanCount]   = useState( null );
    const [aiLoading,     setAiLoading]     = useState( false );

    /* ── Keyword scan ─────────────────────────────────────────────── */

    const fetchKeywordSuggestions = async ( overrideContent = null ) => {
        const scanContent = overrideContent || appliedContentRef.current || wp.data.select( 'core/editor' ).getEditedPostAttribute( 'content' );
        const res = await fetch( `${ linkiyaData.restUrl }/suggest`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': linkiyaData.nonce },
            body: JSON.stringify( { post_id: postId, content: scanContent } ),
        } );
        const data = await res.json();
        if ( ! res.ok ) throw new Error( data.message || data.error || __( 'Server error', 'linkiya' ) );
        if ( data.blacklisted ) throw Object.assign( new Error( data.message || __( 'This post is blacklisted from linking.', 'linkiya' ) ), { blacklisted: true } );
        return data.suggestions || [];
    };

    /* ── AI scan ──────────────────────────────────────────────────── */

    const fetchAiSuggestions = async () => {
        if ( ! aiEnabled || ! aiNonce || ! aiUrl ) return [];

        const liveContent = appliedContentRef.current || wp.data.select( 'core/editor' ).getEditedPostAttribute( 'content' );
        const formData = new FormData();
        formData.append( 'action',   'linkiya_ai_suggest' );
        formData.append( 'nonce',    aiNonce );
        formData.append( 'post_id',  postId );
        formData.append( 'content',  liveContent );

        const res  = await fetch( aiUrl, { method: 'POST', body: formData } );
        const data = await res.json();

        if ( ! data.success ) return []; // AI failure is non-fatal — just return empty
        return ( data.data || [] ).map( s => ( { ...s, source: 'ai' } ) );
    };

    /* ── Run analysis (keyword + AI in parallel) ──────────────────── */

    const runAnalysis = async ( overrideContent = null ) => {
        if ( ! overrideContent ) {
            appliedContentRef.current = null;
        }
        setStatus( STATUS.LOADING );
        setSuggestions( [] );
        setChecked( {} );
        setAnchorTexts( {} );
        setEditingAnchor( {} );
        setErrorMsg( '' );
        setAiLoading( aiEnabled );

        try {
            // Run both in parallel — AI failure won't block keyword results
            const [ keywordSuggs, aiSuggs ] = await Promise.all( [
                fetchKeywordSuggestions( overrideContent ),
                fetchAiSuggestions().catch( () => [] ),
            ] );

            setAiLoading( false );

            // Deduplicate: if AI suggests a post already covered by keyword match, drop the AI entry
            const keywordPostIds = new Set( keywordSuggs.map( s => s.post_id ) );
            const filteredAi     = aiSuggs.filter( s => ! keywordPostIds.has( s.post_id ) );

            // Keyword suggestions first, AI suggestions after
            const merged = [ ...keywordSuggs, ...filteredAi ];

            setSuggestions( merged );

            const defaultChecked = {};
            const defaultAnchors = {};
            merged.forEach( s => {
                const key = suggKey( s );
                defaultChecked[ key ] = true;
                defaultAnchors[ key ] = s.anchor || s.keyword;
            } );
            setChecked( defaultChecked );
            setAnchorTexts( defaultAnchors );
            setStatus( STATUS.DONE );
        } catch ( err ) {
            setAiLoading( false );
            setErrorMsg( err.message );
            setStatus( STATUS.ERROR );
        }
    };

    /* ── Apply links ──────────────────────────────────────────────── */

    const applyLinks = async () => {
        const accepted = suggestions
            .filter( s => checked[ suggKey( s ) ] )
            .map( s => ( {
                keyword:    String( s.keyword    || '' ),
                anchor:     String( anchorTexts[ suggKey( s ) ] || s.anchor || s.keyword || '' ),
                post_id:    Number( s.post_id    || 0 ),
                post_title: String( s.post_title || '' ),
                url:        String( s.url        || '' ),
                nofollow:   !! s.nofollow,
                new_tab:    !! s.new_tab,
            } ) );

        if ( ! accepted.length ) return;
        setStatus( STATUS.APPLYING );

        try {
            const applyContent = appliedContentRef.current || wp.data.select( 'core/editor' ).getEditedPostAttribute( 'content' );
            const res = await fetch( `${ linkiyaData.restUrl }/apply`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': linkiyaData.nonce },
                body: JSON.stringify( { post_id: postId, content: applyContent, accepted } ),
            } );
            const data = await res.json();
            if ( ! res.ok ) throw new Error( data.message || data.error || __( 'Apply failed', 'linkiya' ) );

            const blocks = wp.blocks.parse( data.new_content );
            await resetBlocks( blocks );
            setAppliedCount( data.applied );
            setStatus( STATUS.APPLIED );
            // Store applied content so rescan uses it instead of stale block tree.
            appliedContentRef.current = data.new_content;
        } catch ( err ) {
            setErrorMsg( err.message );
            setStatus( STATUS.ERROR );
        }
    };

    /* ── Remove all links ────────────────────────────────────────────── */

    const removeLinks = async () => {
        const liveContent = appliedContentRef.current || wp.data.select( 'core/editor' ).getEditedPostAttribute( 'content' );
        // Strip all <a> tags but keep their inner text.
        const stripped = liveContent.replace( /<a\b[^>]*>(.*?)<\/a>/gis, '$1' );
        const blocks = wp.blocks.parse( stripped );
        await resetBlocks( blocks );
        setSuggestions( [] );
        setChecked( {} );
        setStatus( STATUS.IDLE );
    };

    /* ── Helpers ──────────────────────────────────────────────────── */

    const toggleAll = val => {
        const next = {};
        suggestions.forEach( s => ( next[ suggKey( s ) ] = val ) );
        setChecked( next );
    };

    const toggleAnchorEdit = key =>
        setEditingAnchor( prev => ( { ...prev, [key]: ! prev[key] } ) );

    const selectedCount = Object.values( checked ).filter( Boolean ).length;

    const keywordCount = suggestions.filter( s => s.source !== 'ai' ).length;
    const aiCount      = suggestions.filter( s => s.source === 'ai' ).length;

    /* ── Render ───────────────────────────────────────────────────── */

    return (
        <>
            <PluginSidebarMoreMenuItem target="linkiya-sidebar">
                { __( 'Linkiya', 'linkiya' ) }
                { isPro && <span className="linkiya-pro-menu-badge">PRO</span> }
            </PluginSidebarMoreMenuItem>

            <PluginSidebar name="linkiya-sidebar" title={ __( 'Linkiya', 'linkiya' ) } icon={ ICON }>
                <PanelBody>

                    { /* Pro / Free banner */ }
                    { isPro ? (
                        <PanelRow>
                            <div className="linkiya-pro-badge">⚡ { __( 'Pro — All features active', 'linkiya' ) }</div>
                        </PanelRow>
                    ) : (
                        <PanelRow>
                            <div className="linkiya-free-banner">
                                { __( 'Free version — scanning Posts & Pages only.', 'linkiya' ) }
                                { ' ' }
                                <a href={ linkiyaData.upgradeUrl } target="_blank" rel="noreferrer">
                                    { __( 'Upgrade to Pro →', 'linkiya' ) }
                                </a>
                            </div>
                        </PanelRow>
                    ) }

                    <PanelRow>
                        <p className="linkiya-intro">
                            { __( 'Scan content for keywords found in published posts and apply internal links.', 'linkiya' ) }
                            { aiEnabled && (
                                <span className="linkiya-ai-active-note">
                                    { ' ' }🤖 { __( 'AI suggestions active', 'linkiya' ) }
                                </span>
                            ) }
                        </p>
                    </PanelRow>

                    { /* Orphan count teaser */ }
                    { orphanCount !== null && orphanCount > 0 && (
                        <PanelRow>
                            <div className="linkiya-orphan-teaser">
                                <span className="linkiya-orphan-count">⚠️ { orphanCount }</span>
                                { ' ' + __( 'orphaned posts found', 'linkiya' ) + ' ' }
                                <a
                                    href={ isPro ? adminUrl( 'linkiya-orphans' ) : linkiyaData.upgradeUrl }
                                    className="linkiya-orphan-link"
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    { isPro ? __( 'Fix them →', 'linkiya' ) : __( 'Upgrade to Pro →', 'linkiya' ) }
                                </a>
                            </div>
                        </PanelRow>
                    ) }

                    { /* Run button */ }
                    { ( status === STATUS.IDLE || status === STATUS.ERROR || status === STATUS.APPLIED ) && (
                        <PanelRow>
                            <Button variant="primary" className="linkiya-run-btn" onClick={ runAnalysis } icon={ ICON }>
                                { __( 'Run Internal Linking', 'linkiya' ) }
                            </Button>
                        </PanelRow>
                    ) }

                    { /* Remove all links button — always visible */ }
                    <PanelRow>
                        <Button variant="secondary" className="linkiya-remove-btn" onClick={ removeLinks } isDestructive disabled={ status === STATUS.LOADING || status === STATUS.APPLYING }>
                            { __( 'Remove All Links', 'linkiya' ) }
                        </Button>
                    </PanelRow>

                    { status === STATUS.LOADING && (
                        <PanelRow>
                            <div className="linkiya-loading">
                                <Spinner />
                                <span>
                                    { aiLoading
                                        ? __( 'Analysing content + fetching AI suggestions…', 'linkiya' )
                                        : __( 'Analysing content…', 'linkiya' )
                                    }
                                </span>
                            </div>
                        </PanelRow>
                    ) }

                    { status === STATUS.ERROR && (
                        <PanelRow>
                            <Notice status="error" isDismissible={ false }>{ errorMsg }</Notice>
                        </PanelRow>
                    ) }

                    { status === STATUS.APPLIED && (
                        <PanelRow>
                            <Notice status="success" isDismissible={ false }>
                                { sprintf(
                                    _n( '%d internal link applied!', '%d internal links applied!', appliedCount, 'linkiya' ),
                                    appliedCount
                                ) }
                                { ' ' }{ __( 'Hit', 'linkiya' ) }{ ' ' }
                                <strong>{ __( 'Update', 'linkiya' ) }</strong>{ ' ' }
                                { __( 'to save.', 'linkiya' ) }
                            </Notice>
                        </PanelRow>
                    ) }

                    { status === STATUS.DONE && (
                        <>
                            { suggestions.length === 0 ? (
                                <PanelRow>
                                    <Notice status="info" isDismissible={ false }>
                                        { __( 'No opportunities found in this post.', 'linkiya' ) }
                                    </Notice>
                                </PanelRow>
                            ) : (
                                <>
                                    <PanelRow>
                                        <div className="linkiya-summary">
                                            <span className="linkiya-badge">{ suggestions.length }</span>
                                            { ' ' + __( 'opportunities found', 'linkiya' ) }
                                            { aiCount > 0 && (
                                                <span className="linkiya-ai-summary-badge">
                                                    🤖 { aiCount } { __( 'AI', 'linkiya' ) }
                                                </span>
                                            ) }
                                        </div>
                                    </PanelRow>
                                    <PanelRow>
                                        <div className="linkiya-select-controls">
                                            <button className="linkiya-text-btn" onClick={ () => toggleAll( true ) }>
                                                { __( 'Select All', 'linkiya' ) }
                                            </button>
                                            <span className="linkiya-divider">|</span>
                                            <button className="linkiya-text-btn" onClick={ () => toggleAll( false ) }>
                                                { __( 'Select None', 'linkiya' ) }
                                            </button>
                                        </div>
                                    </PanelRow>

                                    <div className="linkiya-suggestions-list">
                                        { suggestions.map( s => {
                                            const key   = suggKey( s );
                                            const isAi  = s.source === 'ai';
                                            return (
                                                <div key={ key } className={ `linkiya-suggestion-row${ isAi ? ' linkiya-suggestion-row--ai' : '' }` }>
                                                    <div className="linkiya-suggestion-main">
                                                        <CheckboxControl
                                                            checked={ !! checked[ key ] }
                                                            onChange={ val => setChecked( prev => ( { ...prev, [ key ]: val } ) ) }
                                                            label={
                                                                <span className="linkiya-suggestion-label">
                                                                    <span className="linkiya-keyword">
                                                                        { anchorTexts[ key ] || s.anchor || s.keyword }
                                                                    </span>
                                                                    <span className="linkiya-arrow">→</span>
                                                                    <span className="linkiya-post-title" title={ s.post_title }>
                                                                        { s.post_title.length > 32
                                                                            ? s.post_title.substring( 0, 32 ) + '…'
                                                                            : s.post_title }
                                                                    </span>
                                                                    { s.post_type && s.post_type !== 'post' && s.post_type !== 'page' && (
                                                                        <span className="linkiya-cpt-badge">{ s.post_type }</span>
                                                                    ) }
                                                                    { isAi && (
                                                                        <span
                                                                            className="linkiya-ai-badge"
                                                                            title={ s.reason || __( 'AI-powered suggestion', 'linkiya' ) }
                                                                        >
                                                                            🤖 { __( 'AI', 'linkiya' ) }
                                                                        </span>
                                                                    ) }
                                                                </span>
                                                            }
                                                        />
                                                        <button
                                                            className={ `linkiya-edit-anchor-btn${ editingAnchor[ key ] ? ' active' : '' }` }
                                                            onClick={ () => toggleAnchorEdit( key ) }
                                                            title={ __( 'Edit anchor text', 'linkiya' ) }
                                                            type="button"
                                                        >✏️</button>
                                                    </div>

                                                    { /* AI reason tooltip */ }
                                                    { isAi && s.reason && (
                                                        <div className="linkiya-ai-reason">
                                                            💡 { s.reason }
                                                        </div>
                                                    ) }

                                                    { editingAnchor[ key ] && (
                                                        <div className="linkiya-anchor-edit">
                                                            <TextControl
                                                                label={ __( 'Anchor text', 'linkiya' ) }
                                                                value={ anchorTexts[ key ] || s.anchor || s.keyword }
                                                                onChange={ val => setAnchorTexts( prev => ( { ...prev, [key]: val } ) ) }
                                                                placeholder={ s.anchor || s.keyword }
                                                                help={ __( 'This text will be used as the link anchor.', 'linkiya' ) }
                                                            />
                                                        </div>
                                                    ) }
                                                </div>
                                            );
                                        } ) }
                                    </div>

                                    <PanelRow>
                                        <div className="linkiya-apply-row">
                                            <Button
                                                variant="primary"
                                                className="linkiya-apply-btn"
                                                onClick={ applyLinks }
                                                disabled={ selectedCount === 0 || status === STATUS.APPLYING }
                                                isBusy={ status === STATUS.APPLYING }
                                            >
                                                { status === STATUS.APPLYING
                                                    ? __( 'Applying…', 'linkiya' )
                                                    : sprintf(
                                                        _n( 'Apply %d Link', 'Apply %d Links', selectedCount, 'linkiya' ),
                                                        selectedCount
                                                    )
                                                }
                                            </Button>
                                            <Button
                                                variant="secondary"
                                                className="linkiya-rerun-btn"
                                                onClick={ runAnalysis }
                                                disabled={ status === STATUS.APPLYING }
                                            >
                                                { __( 'Re-scan', 'linkiya' ) }
                                            </Button>
                                        </div>
                                    </PanelRow>
                                </>
                            ) }
                        </>
                    ) }

                </PanelBody>
            </PluginSidebar>
        </>
    );
}

registerPlugin( 'linkiya', { render: SmartInternalLinkerSidebar, icon: ICON } );
