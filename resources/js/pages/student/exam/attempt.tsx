import { Head, router } from '@inertiajs/react';
import { AlertTriangle, ChevronLeft, ChevronRight, Lock, ShieldAlert, Download, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import {
   AlertDialog,
   AlertDialogAction,
   AlertDialogCancel,
   AlertDialogContent,
   AlertDialogDescription,
   AlertDialogFooter,
   AlertDialogHeader,
   AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import Footer from '@/layouts/footer';
import Main from '@/layouts/main';
import examAttempts from '@/routes/exam-attempts';
import AttemptNavbar from './partials/attempt-navbar';
import QuestionNavigator from './partials/question-navigator';
import QuestionRenderer from './partials/question-renderer';
import TimerComponent from './partials/timer-component';

interface Props {
   attempt: ExamAttempt;
   bootstrapToken?: string;
   asapSessionId?: string;
   asapProtocolUrl?: string;
}

function loadExamDraftFromStorage(attemptId: number): {
   answers: Record<number, any>;
   marked: Set<number>;
} {
   if (typeof window === 'undefined') {
      return { answers: {}, marked: new Set() };
   }

   const raw = localStorage.getItem(`exam-attempt-${attemptId}`);

   if (!raw) {
      return { answers: {}, marked: new Set() };
   }

   try {
      const parsed = JSON.parse(raw);

      return {
         answers: parsed.answers || {},
         marked: new Set(parsed.marked || []),
      };
   } catch (error) {
      console.error('Failed to load saved answers:', error);

      return { answers: {}, marked: new Set() };
   }
}

const TakeExam = ({ attempt, bootstrapToken, asapSessionId, asapProtocolUrl }: Props) => {
   const isAsapClient = typeof window !== 'undefined' && (window as any).asap !== undefined;
   const [sessionVerified, setSessionVerified] = useState(false);
   const [verificationError, setVerificationError] = useState<string | null>(null);

   useEffect(() => {
      if (!isAsapClient) return;

      let active = true;
      let pollTimeout: NodeJS.Timeout;

      const bootstrapAndPoll = async () => {
         try {
            if (bootstrapToken && asapSessionId) {
               const success = await (window as any).asap.bootstrap(bootstrapToken, asapSessionId, window.location.origin);
               if (!success) {
                  if (active) setVerificationError("Handshake with Secure Client failed.");
                  return;
               }
               
               // Poll session status from backend
               const pollStatus = async () => {
                  if (!active) return;
                  try {
                     const res = await fetch(`/api/v1/asap/session/${asapSessionId}`, {
                        headers: {
                           'Accept': 'application/json'
                        }
                     });
                     if (res.ok) {
                        const data = await res.json();
                        if (data.status === 'success' && data.session) {
                           const status = data.session.status;
                           if (status === 'running' || status === 'ready') {
                              if (active) setSessionVerified(true);
                              return;
                           } else if (status === 'terminated') {
                              if (active) setVerificationError("Session has been terminated by the security policy.");
                              return;
                           }
                        }
                     }
                  } catch (err) {
                     console.error("Error polling session status:", err);
                  }
                  pollTimeout = setTimeout(pollStatus, 2000);
               };

               pollStatus();
            } else {
               if (active) setVerificationError("Missing session parameters.");
            }
         } catch (e) {
            if (active) setVerificationError("Error initializing secure client.");
         }
      };

      bootstrapAndPoll();

      return () => {
         active = false;
         clearTimeout(pollTimeout);
      };
   }, [isAsapClient, bootstrapToken, asapSessionId]);

   const [currentQuestionIndex, setCurrentQuestionIndex] = useState(0);
   const [answers, setAnswers] = useState<Record<number, any>>(
      () => loadExamDraftFromStorage(attempt.id).answers,
   );
   const [markedQuestions, setMarkedQuestions] = useState<Set<number>>(
      () => loadExamDraftFromStorage(attempt.id).marked,
   );
   const [showSubmitDialog, setShowSubmitDialog] = useState(false);
   const [isSubmitting, setIsSubmitting] = useState(false);
   const [fallbackAttemptStartMs] = useState(() => Date.now());

   const durationSeconds =
      ((attempt.exam.duration_hours || 0) * 60 +
         (attempt.exam.duration_minutes || 0)) *
      60;
   const attemptStart = attempt.start_time
      ? new Date(attempt.start_time).getTime()
      : fallbackAttemptStartMs;

   const effectiveDuration = durationSeconds > 0 ? durationSeconds : 60 * 60; // default to 1 hour when not configured
   const computedDeadline = attempt.end_time
      ? attempt.end_time
      : new Date(attemptStart + effectiveDuration * 1000).toISOString();

   const questions = attempt.exam.questions || [];
   const currentQuestion = questions[currentQuestionIndex];
   const answeredQuestions = new Set(Object.keys(answers).map(Number));

   const saveToLocalStorage = useCallback(() => {
      localStorage.setItem(
         `exam-attempt-${attempt.id}`,
         JSON.stringify({
            answers,
            marked: Array.from(markedQuestions),
            lastSaved: new Date().toISOString(),
         }),
      );
   }, [attempt.id, answers, markedQuestions]);

   // Auto-save answers to localStorage every 30 seconds
   useEffect(() => {
      const interval = setInterval(() => {
         saveToLocalStorage();
      }, 30000);

      return () => clearInterval(interval);
   }, [saveToLocalStorage]);

   // Warn before leaving page
   useEffect(() => {
      const handleBeforeUnload = (e: BeforeUnloadEvent) => {
         e.preventDefault();
         e.returnValue = '';
      };

      window.addEventListener('beforeunload', handleBeforeUnload);

      return () =>
         window.removeEventListener('beforeunload', handleBeforeUnload);
   }, []);

   const saveAnswerToBackend = async (questionId: number, answer: any) => {
      // await router.post(
      //    examAttempts.answer.url(attempt.id),
      //    {
      //       question_id: questionId,
      //       answer_data: answer,
      //    },
      //    {
      //       preserveScroll: true,
      //       preserveState: true,
      //    },
      // );
   };

   const handleAnswerChange = (answer: any) => {
      if (!currentQuestion) {
         return;
      }

      setAnswers((prev) => ({
         ...prev,
         [currentQuestion.id]: answer,
      }));

      // Save to backend
      saveAnswerToBackend(currentQuestion.id as number, answer);
      saveToLocalStorage();
   };

   const handlePrevious = () => {
      if (currentQuestionIndex > 0) {
         setCurrentQuestionIndex(currentQuestionIndex - 1);
      }
   };

   const handleNext = () => {
      if (currentQuestionIndex < questions.length - 1) {
         setCurrentQuestionIndex(currentQuestionIndex + 1);
      }
   };

   const handleSubmit = async () => {
      setIsSubmitting(true);
      saveToLocalStorage();

      const formattedAnswers = Object.entries(answers).map(
         ([questionId, value]) => ({
            exam_question_id: Number(questionId),
            answer_data: value,
         }),
      );

      router.post(
         examAttempts.submit(attempt.id),
         {
            exam_attempt_id: attempt.id,
            answers: formattedAnswers,
         },
         {
            onSuccess: () => {
               localStorage.removeItem(`exam-attempt-${attempt.id}`);
            },
            onFinish: () => {
               setIsSubmitting(false);
            },
         },
      );
   };

   // Keyboard shortcuts
   useEffect(() => {
      const handleKeyPress = (e: KeyboardEvent) => {
         if (e.key === 'ArrowRight') {
            setCurrentQuestionIndex((i) =>
               i < questions.length - 1 ? i + 1 : i,
            );
         } else if (e.key === 'ArrowLeft') {
            setCurrentQuestionIndex((i) => (i > 0 ? i - 1 : i));
         }
      };

      window.addEventListener('keydown', handleKeyPress);

      return () => window.removeEventListener('keydown', handleKeyPress);
   }, [questions.length]);

   const unansweredCount = questions.length - answeredQuestions.size;

   // If not in the ASAP client, block access and show the launch page
   if (!isAsapClient) {
      const launchUrl = asapProtocolUrl || `asap://open?url=${encodeURIComponent(window.location.href)}`;
      return (
         <Main>
            <Head title="Secure Client Required" />
            <div className="flex min-h-screen items-center justify-center bg-slate-950 p-4 text-slate-100">
               <div className="w-full max-w-xl rounded-2xl border border-slate-800 bg-slate-900/60 p-8 shadow-2xl backdrop-blur-xl">
                  <div className="flex flex-col items-center text-center">
                     <div className="relative mb-6">
                        <div className="absolute inset-0 animate-ping rounded-full bg-red-500/20 opacity-75"></div>
                        <div className="relative flex h-16 w-16 items-center justify-center rounded-full bg-red-500/10 text-red-500 border border-red-500/30 animate-pulse">
                           <Lock className="h-8 w-8" />
                        </div>
                     </div>
                     
                     <h1 className="mb-3 text-2xl font-bold tracking-tight text-white md:text-3xl">
                        Secure Environment Required
                     </h1>
                     
                     <p className="mb-6 text-sm text-slate-400 leading-relaxed max-w-md">
                        This exam is protected by the <strong className="text-slate-200">Secure Assessment Platform (ASAP)</strong>. To ensure integrity, you must complete this assessment inside the secure desktop client.
                     </p>

                     <div className="mb-8 flex w-full flex-col gap-3 sm:flex-row">
                        <a 
                           href={launchUrl}
                           className="flex flex-1 items-center justify-center gap-2 rounded-xl bg-primary px-4 py-3 text-sm font-semibold text-primary-foreground shadow transition hover:bg-primary/90"
                        >
                           <ShieldAlert className="h-4 w-4" />
                           Launch ASAP Desktop
                        </a>
                        <button 
                           onClick={() => window.open('/assets/downloads/asap-setup.exe', '_blank')}
                           className="flex flex-1 items-center justify-center gap-2 rounded-xl border border-slate-700 bg-slate-800/40 px-4 py-3 text-sm font-semibold text-slate-300 transition hover:bg-slate-800"
                        >
                           <Download className="h-4 w-4" />
                           Download Client
                        </button>
                     </div>

                     <div className="w-full rounded-xl bg-slate-800/30 p-5 text-left border border-slate-800">
                        <h3 className="mb-3 text-xs font-bold uppercase tracking-wider text-slate-400">
                           Instructions:
                        </h3>
                        <ol className="list-decimal space-y-2.5 pl-4 text-xs text-slate-400">
                           <li>Click <strong className="text-slate-300">Launch ASAP Desktop</strong> above to open the application.</li>
                           <li>If your browser asks for permission to open the protocol link, click <strong className="text-slate-300">Open/Allow</strong>.</li>
                           <li>Sign in inside the application to resume and start your exam.</li>
                           <li>If the application is not installed, click <strong className="text-slate-300">Download Client</strong> to install it first.</li>
                        </ol>
                     </div>
                  </div>
               </div>
            </div>
         </Main>
      );
   }

   // If inside client but session is not verified yet
   if (!sessionVerified) {
      return (
         <Main>
            <Head title="Verifying Connection" />
            <div className="flex min-h-screen items-center justify-center bg-slate-950 p-4 text-slate-100">
               <div className="w-full max-w-md rounded-2xl border border-slate-800 bg-slate-900/60 p-8 shadow-2xl text-center backdrop-blur-xl">
                  {verificationError ? (
                     <div className="flex flex-col items-center">
                        <div className="mb-5 flex h-14 w-14 items-center justify-center rounded-full bg-red-500/10 text-red-500 border border-red-500/20">
                           <ShieldAlert className="h-7 w-7" />
                        </div>
                        <h2 className="mb-2 text-xl font-semibold text-white">Security Initialization Failed</h2>
                        <p className="mb-6 text-sm text-slate-400">{verificationError}</p>
                        <Button 
                           onClick={() => window.location.reload()} 
                           className="w-full gap-2 rounded-xl"
                        >
                           <RefreshCw className="h-4 w-4" />
                           Retry Connection
                        </Button>
                     </div>
                  ) : (
                     <div className="flex flex-col items-center">
                        <div className="mb-6 relative">
                           <div className="h-14 w-14 rounded-full border-2 border-t-primary border-r-transparent border-b-transparent border-l-transparent animate-spin"></div>
                           <div className="absolute inset-0 flex items-center justify-center text-primary">
                              <Lock className="h-5 w-5 animate-pulse" />
                           </div>
                        </div>
                        <h2 className="mb-2 text-lg font-semibold text-white">Verifying Secure Connection...</h2>
                        <p className="text-xs text-slate-400">
                           Establishing secure handshake with the Assessment Platform. Please wait.
                        </p>
                     </div>
                  )}
               </div>
            </div>
         </Main>
      );
   }

   return (
      <Main>
         <Head title={`Taking: ${attempt.exam.title}`} />

         <main className="flex min-h-screen flex-col justify-between overflow-x-hidden">
            <AttemptNavbar
               attempt={attempt}
               questionIndex={currentQuestionIndex}
            />

            <div className="container py-12">
               <div className="space-y-4">
                  <div className="grid grid-cols-1 gap-4 lg:grid-cols-4">
                     {/* Main Content */}
                     <div className="space-y-4 lg:col-span-3">
                        {/* Timer */}
                        <TimerComponent
                           key={`${attempt.id}-${computedDeadline}`}
                           attempt={attempt}
                           endTime={computedDeadline}
                           questionIndex={currentQuestionIndex}
                        />

                        {/* Question */}
                        {currentQuestion && (
                           <QuestionRenderer
                              question={currentQuestion}
                              questionNumber={currentQuestionIndex + 1}
                              answer={answers[currentQuestion.id as number]}
                              onAnswerChange={handleAnswerChange}
                           />
                        )}

                        {/* Navigation */}
                        <div className="flex items-center justify-between rounded-lg bg-white p-4 shadow">
                           <Button
                              onClick={handlePrevious}
                              disabled={currentQuestionIndex === 0}
                              variant="outline"
                           >
                              <ChevronLeft className="mr-2 h-4 w-4" />
                              Previous
                           </Button>

                           {/* <Button onClick={handleMarkForReview} variant={markedQuestions.has(currentQuestion.id as number) ? 'default' : 'outline'}>
                           <Flag className="mr-2 h-4 w-4" />
                           {markedQuestions.has(currentQuestion.id as number) ? 'Unmark' : 'Mark for Review'}
                        </Button> */}

                           {currentQuestionIndex < questions.length - 1 ? (
                              <Button onClick={handleNext}>
                                 Next
                                 <ChevronRight className="ml-2 h-4 w-4" />
                              </Button>
                           ) : (
                              <Button
                                 onClick={() => setShowSubmitDialog(true)}
                                 variant="default"
                              >
                                 Submit Exam
                              </Button>
                           )}
                        </div>
                     </div>

                     {/* Sidebar */}
                     <div className="lg:col-span-1">
                        <QuestionNavigator
                           questions={questions}
                           currentQuestionIndex={currentQuestionIndex}
                           answeredQuestions={answeredQuestions}
                           markedQuestions={markedQuestions}
                           onNavigate={setCurrentQuestionIndex}
                        />
                     </div>
                  </div>
               </div>

               {/* Submit Confirmation Dialog */}
               <AlertDialog
                  open={showSubmitDialog}
                  onOpenChange={setShowSubmitDialog}
               >
                  <AlertDialogContent>
                     <AlertDialogHeader>
                        <AlertDialogTitle className="flex items-center gap-2">
                           <AlertTriangle className="h-5 w-5 text-yellow-600" />
                           Submit Exam?
                        </AlertDialogTitle>
                        <AlertDialogDescription className="space-y-3">
                           <p>
                              Are you sure you want to submit your exam? This
                              action cannot be undone.
                           </p>
                           {unansweredCount > 0 && (
                              <div className="rounded-lg bg-yellow-50 p-3">
                                 <p className="text-sm font-semibold text-yellow-800">
                                    Warning: You have {unansweredCount}{' '}
                                    unanswered question
                                    {unansweredCount > 1 ? 's' : ''}!
                                 </p>
                              </div>
                           )}
                           <div className="text-sm">
                              <p>
                                 <strong>Answered:</strong>{' '}
                                 {answeredQuestions.size} / {questions.length}
                              </p>
                              <p>
                                 <strong>Marked for review:</strong>{' '}
                                 {markedQuestions.size}
                              </p>
                           </div>
                        </AlertDialogDescription>
                     </AlertDialogHeader>
                     <AlertDialogFooter>
                        <AlertDialogCancel
                           onClick={() => setShowSubmitDialog(false)}
                        >
                           Cancel
                        </AlertDialogCancel>
                        <AlertDialogAction
                           onClick={handleSubmit}
                           disabled={isSubmitting}
                        >
                           {isSubmitting ? 'Submitting...' : 'Yes, Submit Exam'}
                        </AlertDialogAction>
                     </AlertDialogFooter>
                  </AlertDialogContent>
               </AlertDialog>
            </div>

            <Footer />
         </main>
      </Main>
   );
};

export default TakeExam;
